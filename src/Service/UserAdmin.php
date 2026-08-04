<?php

namespace App\Service;

use App\Entity\User;
use App\Enum\AuditAction;
use App\Repository\UserRepository;
use App\Security\RememberMeRevoker;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Account administration, with the lockout guards in one place.
 *
 * Every mutation refuses two things: an admin disarming themselves, and any change
 * that would leave zero active administrators. Without those, the only way back into
 * /admin would be `app:user:create --admin` on the server.
 */
final class UserAdmin
{
    public const MIN_PASSWORD_LENGTH = 8;

    public function __construct(
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly RememberMeRevoker $rememberMe,
        private readonly AuditLog $audit,
    ) {
    }

    /**
     * @throws \DomainException when the address is taken or the password is too short
     */
    public function create(string $email, ?string $displayName, string $password, bool $admin, ?User $actor = null): User
    {
        $email = mb_strtolower(trim($email));

        if ($this->users->findOneBy(['email' => $email]) !== null) {
            throw new \DomainException(sprintf('Ya existe una cuenta con el correo %s.', $email));
        }

        $this->assertPasswordAcceptable($password);

        $user = (new User())
            ->setEmail($email)
            ->setDisplayName($displayName !== null && trim($displayName) !== '' ? trim($displayName) : null)
            ->setAdmin($admin)
            ->setActive(true);

        $user->setPassword($this->hasher->hashPassword($user, $password));

        $this->em->persist($user);
        // Flushed first so the new account has an id to record against.
        $this->em->flush();

        $this->audit->forUser(AuditAction::USER_CREATED, $user, $actor, ['administrador' => $admin]);
        $this->em->flush();

        return $user;
    }

    /**
     * @throws \DomainException when this would disarm the actor or remove the last admin
     */
    public function setAdmin(User $target, bool $admin, User $actor): void
    {
        if (!$admin) {
            $this->assertNotSelf($target, $actor, 'No puedes retirarte tus propios permisos de administrador.');
            $this->assertNotLastAdmin($target);
        }

        $target->setAdmin($admin);
        $this->audit->forUser($admin ? AuditAction::USER_PROMOTED : AuditAction::USER_DEMOTED, $target, $actor);
        $this->em->flush();
    }

    /**
     * @throws \DomainException when this would lock out the actor or the last admin
     */
    public function setActive(User $target, bool $active, User $actor): void
    {
        if (!$active) {
            $this->assertNotSelf($target, $actor, 'No puedes desactivar tu propia cuenta.');
            $this->assertNotLastAdmin($target);
        }

        $target->setActive($active);
        $this->audit->forUser($active ? AuditAction::USER_ACTIVATED : AuditAction::USER_DEACTIVATED, $target, $actor);
        $this->em->flush();

        if (!$active) {
            // UserChecker already refuses them, but leaving live tokens behind for a
            // disabled account is needless exposure if the check is ever relaxed.
            $this->rememberMe->revokeAll($target);
        }
    }

    /**
     * @throws \DomainException when the password is too short
     */
    public function resetPassword(User $target, string $password, ?User $actor = null): void
    {
        $this->assertPasswordAcceptable($password);

        $target->setPassword($this->hasher->hashPassword($target, $password));
        // The password itself is of course never recorded — only that it changed.
        $this->audit->forUser(AuditAction::USER_PASSWORD_RESET, $target, $actor);
        $this->em->flush();

        // Persistent remember-me tokens are not tied to the password hash, so they
        // survive a reset unless deleted. Someone changing a password expects the
        // old credential to stop working everywhere.
        $this->rememberMe->revokeAll($target);
    }

    private function assertPasswordAcceptable(string $password): void
    {
        if (mb_strlen($password) < self::MIN_PASSWORD_LENGTH) {
            throw new \DomainException(sprintf(
                'La contraseña debe tener al menos %d caracteres.',
                self::MIN_PASSWORD_LENGTH,
            ));
        }
    }

    private function assertNotSelf(User $target, User $actor, string $message): void
    {
        if ($target->getId() === $actor->getId()) {
            throw new \DomainException($message);
        }
    }

    /**
     * Blocks the change only when the target is currently one of the active admins
     * and is the only one — demoting a non-admin or an already-inactive account
     * cannot reduce the count.
     */
    private function assertNotLastAdmin(User $target): void
    {
        if (!$target->isAdmin() || !$target->isActive()) {
            return;
        }

        if (count($this->users->findActiveAdmins()) <= 1) {
            throw new \DomainException(
                'Debe quedar al menos un administrador activo. Nombra a otro antes de hacer este cambio.'
            );
        }
    }
}
