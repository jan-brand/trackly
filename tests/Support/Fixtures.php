<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Db\Db;

/**
 * Test helpers for creating users and assigning roles in a deterministic way.
 *
 * Valid role keys (must match seed data in db/seeds/0001_roles.php):
 *   employee | coordination | treasurer | admin
 */
class Fixtures
{
    /**
     * Insert a user and return its new ID.
     */
    public static function createUser(
        string $email,
        string $password,
        bool $active = true,
    ): int {
        $pdo = Db::pdo();

        $stmt = $pdo->prepare(
            'INSERT INTO users (email, password_hash, is_active)
             VALUES (:email, :hash, :active)',
        );

        $stmt->execute([
            ':email'  => $email,
            ':hash'   => password_hash($password, PASSWORD_DEFAULT),
            ':active' => $active ? 1 : 0,
        ]);

        return (int) $pdo->lastInsertId();
    }

    /**
     * Assign a role (by its key) to a user.
     *
     * @throws \RuntimeException when the role key is not found in the database.
     */
    public static function assignRole(int $userId, string $roleKey): void
    {
        $pdo = Db::pdo();

        $stmt = $pdo->prepare('SELECT id FROM roles WHERE `key` = :key LIMIT 1');
        $stmt->execute([':key' => $roleKey]);
        $role = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($role === false) {
            throw new \RuntimeException("Role key '{$roleKey}' not found. Did you run the role seeds?");
        }

        $pdo->prepare(
            'INSERT IGNORE INTO user_roles (user_id, role_id) VALUES (:uid, :rid)',
        )->execute([':uid' => $userId, ':rid' => $role['id']]);
    }
}
