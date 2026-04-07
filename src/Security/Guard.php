<?php

declare(strict_types=1);

namespace App\Security;

use App\Http\ForbiddenException;

/**
 * Central access-control guards.
 *
 * All guards throw exceptions; callers should let the exceptions bubble up
 * to the front controller, which converts them to the correct HTTP response.
 */
class Guard
{
    /**
     * Require the current request to be authenticated.
     *
     * @throws ForbiddenException when no user is logged in.
     */
    public static function requireLogin(): void
    {
        if (!Auth::isLoggedIn()) {
            throw new ForbiddenException('Authentication required.');
        }
    }

    /**
     * Require the user to have at least one of the given roles (OR logic).
     *
     * @param string[] $roles
     * @throws ForbiddenException when the user lacks all listed roles.
     */
    public static function requireRole(array $roles): void
    {
        self::requireLogin();

        if (!Auth::hasAnyRole($roles)) {
            throw new ForbiddenException('Insufficient role.');
        }
    }

    /**
     * Require the current user to be the owner of the resource.
     * admin and coordination roles bypass the ownership check.
     *
     * @throws ForbiddenException when the employee is accessing another user's data.
     */
    public static function requireOwnership(int $targetUserId): void
    {
        self::requireLogin();

        if (Auth::hasAnyRole(['admin', 'coordination'])) {
            return;
        }

        if (Auth::userId() !== $targetUserId) {
            throw new ForbiddenException('Access denied: not the owner.');
        }
    }
}
