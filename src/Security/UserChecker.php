<?php

namespace App\Security;

use App\Entity\User;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Refuses deactivated accounts.
 *
 * checkPreAuth runs on every authentication *and* on every request that restores a
 * session or a remember-me cookie, so deactivating someone takes effect immediately
 * rather than whenever their cookie happens to expire.
 */
final class UserChecker implements UserCheckerInterface
{
    public function checkPreAuth(UserInterface $user): void
    {
        if ($user instanceof User && !$user->isActive()) {
            throw new CustomUserMessageAccountStatusException(
                'Esta cuenta está desactivada. Contacta a un administrador.'
            );
        }
    }

    public function checkPostAuth(UserInterface $user): void
    {
    }
}
