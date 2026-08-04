<?php

namespace App\Security;

use App\Entity\User;
use Doctrine\DBAL\Connection;

/**
 * Deletes a user's stored remember-me tokens.
 *
 * Needed because the firewall uses persistent (Doctrine) tokens rather than the
 * default signature-based ones. Signature tokens are signed over the password
 * hash, so changing a password invalidated them for free; a stored token carries
 * no such link and stays valid until its row is removed. Logout deletes its own
 * row, but "reset this person's password" and "deactivate this account" have to
 * say so explicitly — otherwise a cookie taken from a shared machine outlives
 * both actions.
 *
 * Operates on the table directly: Symfony's DoctrineTokenProvider only exposes
 * per-series deletion, and the point here is to revoke every device at once.
 */
final class RememberMeRevoker
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function revokeAll(User $user): void
    {
        $this->connection->executeStatement(
            'DELETE FROM rememberme_token WHERE username = :username AND class = :class',
            [
                'username' => $user->getUserIdentifier(),
                'class' => User::class,
            ],
        );
    }
}
