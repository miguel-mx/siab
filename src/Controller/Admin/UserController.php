<?php

namespace App\Controller\Admin;

use App\Entity\User;
use App\Repository\AuditLogRepository;
use App\Repository\UserRepository;
use App\Service\UserAdmin;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Account administration.
 *
 * ^/admin is already gated by access_control; the attribute below repeats it so the
 * requirement travels with the class if that rule is ever reordered.
 */
#[Route('/admin/usuarios')]
#[IsGranted('ROLE_ADMIN')]
final class UserController extends AbstractController
{
    #[Route('', name: 'app_admin_users', methods: ['GET'])]
    public function index(UserRepository $users, AuditLogRepository $audit): Response
    {
        $accounts = $users->findAllForAdmin();

        // The last thing done to each account, shown on its row — the audit page has
        // the rest. Keyed by id so the template does no querying of its own.
        $latest = [];
        foreach ($accounts as $account) {
            $latest[$account->getId()] = $audit->findForUser((int) $account->getId(), 1)[0] ?? null;
        }

        return $this->render('admin/users.html.twig', [
            'users' => $accounts,
            'latest_change' => $latest,
            'active_admins' => count($users->findActiveAdmins()),
            'min_password' => UserAdmin::MIN_PASSWORD_LENGTH,
        ]);
    }

    #[Route('/nueva', name: 'app_admin_user_create', methods: ['POST'])]
    public function create(Request $request, UserAdmin $admin): Response
    {
        if (!$this->isCsrfTokenValid('submit', (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'La solicitud caducó. Inténtalo de nuevo.');

            return $this->redirectToRoute('app_admin_users');
        }

        try {
            $actor = $this->getUser();
            $user = $admin->create(
                (string) $request->request->get('email'),
                $request->request->get('display_name'),
                (string) $request->request->get('password'),
                $request->request->getBoolean('is_admin'),
                $actor instanceof User ? $actor : null,
            );
            $this->addFlash('success', sprintf('Cuenta creada: %s.', $user->getEmail()));
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_admin_users');
    }

    #[Route('/{id}/rol', name: 'app_admin_user_role', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function role(Request $request, User $user, UserAdmin $admin): Response
    {
        return $this->mutate($request, function () use ($request, $user, $admin) {
            $grant = $request->request->getBoolean('is_admin');
            $admin->setAdmin($user, $grant, $this->currentUser());

            return sprintf(
                '%s %s administrador. Deberá iniciar sesión de nuevo.',
                $user->getEmail(),
                $grant ? 'ahora es' : 'ya no es',
            );
        });
    }

    #[Route('/{id}/estado', name: 'app_admin_user_state', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function state(Request $request, User $user, UserAdmin $admin): Response
    {
        return $this->mutate($request, function () use ($request, $user, $admin) {
            $activate = $request->request->getBoolean('active');
            $admin->setActive($user, $activate, $this->currentUser());

            return sprintf(
                'Cuenta %s: %s.',
                $activate ? 'reactivada' : 'desactivada',
                $user->getEmail(),
            );
        });
    }

    #[Route('/{id}/contrasena', name: 'app_admin_user_password', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function password(Request $request, User $user, UserAdmin $admin): Response
    {
        return $this->mutate($request, function () use ($request, $user, $admin) {
            $admin->resetPassword($user, (string) $request->request->get('password'), $this->currentUser());

            return sprintf('Contraseña actualizada para %s. Sus sesiones recordadas caducaron.', $user->getEmail());
        });
    }

    /**
     * Shared shape for the three mutations: check CSRF, run it, flash the outcome or
     * the guard's reason, and go back to the list.
     *
     * @param callable(): string $change returns the success message
     */
    private function mutate(Request $request, callable $change): Response
    {
        if (!$this->isCsrfTokenValid('submit', (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'La solicitud caducó. Inténtalo de nuevo.');

            return $this->redirectToRoute('app_admin_users');
        }

        try {
            $this->addFlash('success', $change());
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_admin_users');
    }

    private function currentUser(): User
    {
        $user = $this->getUser();
        \assert($user instanceof User); // guaranteed by IsGranted('ROLE_ADMIN')

        return $user;
    }
}
