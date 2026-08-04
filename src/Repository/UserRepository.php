<?php

namespace App\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;

/**
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository implements PasswordUpgraderInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    /**
     * Accounts for the admin screen: admins first, then by name/email.
     *
     * @return User[]
     */
    public function findAllForAdmin(): array
    {
        $users = $this->createQueryBuilder('u')
            ->orderBy('u.email', 'ASC')
            ->getQuery()
            ->getResult();

        // Sorted in PHP: "is an admin" lives inside a JSON column, and ordering by it
        // in DQL would need a vendor-specific JSON function for a table of CCM staff.
        usort($users, static fn (User $a, User $b) => [$b->isAdmin(), strtolower((string) ($a->getDisplayName() ?: $a->getEmail()))]
            <=> [$a->isAdmin(), strtolower((string) ($b->getDisplayName() ?: $b->getEmail()))]);

        return $users;
    }

    /**
     * Active administrators. The admin screen refuses any change that would take
     * this to zero, which would leave nobody able to reach /admin.
     *
     * @return User[]
     */
    public function findActiveAdmins(): array
    {
        return array_values(array_filter(
            $this->findBy(['active' => true]),
            static fn (User $u) => $u->isAdmin(),
        ));
    }

    /**
     * Used to upgrade (rehash) the user's password automatically over time.
     */
    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException(sprintf('Instances of "%s" are not supported.', $user::class));
        }

        $user->setPassword($newHashedPassword);
        $this->getEntityManager()->persist($user);
        $this->getEntityManager()->flush();
    }
}
