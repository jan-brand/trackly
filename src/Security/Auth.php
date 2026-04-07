<?php

declare(strict_types=1);

namespace App\Security;

use App\Db\Db;

/**
 * Provides the current authenticated user's identity and roles.
 *
 * Roles are loaded from the database once per session and cached in
 * $_SESSION so they are never read from the request.
 */
class Auth
{
    private const SESSION_USER_ID = 'user_id';
    private const SESSION_ROLES   = '__user_roles';

    public static function userId(): ?int
    {
        $id = $_SESSION[self::SESSION_USER_ID] ?? null;
        return $id !== null ? (int) $id : null;
    }

    public static function isLoggedIn(): bool
    {
        return self::userId() !== null;
    }

    /**
     * Return the role keys for the current user.
     * Loaded from the database on the first call; subsequent calls use the
     * session cache, so the DB is never queried more than once per session.
     *
     * @return string[]
     */
    public static function roles(): array
    {
        if (isset($_SESSION[self::SESSION_ROLES])) {
            return (array) $_SESSION[self::SESSION_ROLES];
        }

        $userId = self::userId();
        if ($userId === null) {
            return [];
        }

        try {
            $stmt = Db::pdo()->prepare(
                'SELECT r.`key`
                 FROM roles r
                 JOIN user_roles ur ON ur.role_id = r.id
                 WHERE ur.user_id = :uid'
            );
            $stmt->execute([':uid' => $userId]);
            $roles = $stmt->fetchAll(\PDO::FETCH_COLUMN);
        } catch (\Throwable) {
            $roles = [];
        }

        $_SESSION[self::SESSION_ROLES] = $roles;
        return $roles;
    }

    public static function hasRole(string $role): bool
    {
        return in_array($role, self::roles(), true);
    }

    /** Returns true when the user has at least one of the given roles (OR). */
    public static function hasAnyRole(array $roles): bool
    {
        $userRoles = self::roles();
        foreach ($roles as $role) {
            if (in_array($role, $userRoles, true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Return the default home path for the current user based on their role.
     *
     * - coordination / admin  ⇒ /coordination/queue
     * - treasurer             ⇒ /export
     * - employee (default)    ⇒ /timer
     */
    public static function defaultHome(): string
    {
        if (self::hasAnyRole(['coordination', 'admin'])) {
            return '/coordination/queue';
        }

        if (self::hasRole('treasurer')) {
            return '/export';
        }

        return '/timer';
    }

    /**
     * Remove the cached role list from the session.
     * Call this on logout so stale roles cannot leak into a new session.
     */
    public static function clearRoleCache(): void
    {
        unset($_SESSION[self::SESSION_ROLES]);
    }
}
