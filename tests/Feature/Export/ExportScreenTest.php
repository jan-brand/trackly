<?php

declare(strict_types=1);

namespace App\Tests\Feature\Export;

use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * EX6.2 – GET /export screen tests.
 *
 * Tests:
 *   1. Must-have: employee sees no scope radios.
 *   2. coordination role sees scope radios.
 *   3. treasurer role sees scope radios.
 *   4. unauthenticated ⇒ 403.
 */
class ExportScreenTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $_SESSION = [];
        $_POST    = [];

        $this->pdo = $this->buildSqlitePdo();
        $this->injectPdo($this->pdo);
    }

    protected function tearDown(): void
    {
        $this->resetDb();
        $_SESSION = [];
        $_POST    = [];
    }

    // =========================================================================
    // Must-have: employee sees no scope radios
    // =========================================================================

    public function testEmployeeSeesNoScopeRadios(): void
    {
        $userId = $this->createUser('emp@example.com');

        $result = dispatch(
            'GET',
            '/export',
            [],
            ['user_id' => $userId, '__user_roles' => ['employee']],
        );

        $this->assertSame(200, $result['status']);
        $this->assertStringNotContainsString('name="scope"', $result['body']);
        $this->assertStringNotContainsString('all_users', $result['body']);
    }

    // =========================================================================
    // Coordination role sees scope radios
    // =========================================================================

    public function testCoordinationSeesScopeRadios(): void
    {
        $userId = $this->createUser('coord@example.com');

        $result = dispatch(
            'GET',
            '/export',
            [],
            ['user_id' => $userId, '__user_roles' => ['coordination']],
        );

        $this->assertSame(200, $result['status']);
        $this->assertStringContainsString('name="scope"', $result['body']);
        $this->assertStringContainsString('all_users', $result['body']);
    }

    // =========================================================================
    // Treasurer role sees scope radios
    // =========================================================================

    public function testTreasurerSeesScopeRadios(): void
    {
        $userId = $this->createUser('treasurer@example.com');

        $result = dispatch(
            'GET',
            '/export',
            [],
            ['user_id' => $userId, '__user_roles' => ['treasurer']],
        );

        $this->assertSame(200, $result['status']);
        $this->assertStringContainsString('name="scope"', $result['body']);
    }

    // =========================================================================
    // Unauthenticated ⇒ 403
    // =========================================================================

    public function testUnauthenticatedReturns403(): void
    {
        $result = dispatch('GET', '/export', [], []);

        $this->assertSame(403, $result['status']);
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    private function createUser(string $email): int
    {
        $this->pdo->prepare(
            'INSERT INTO users (email, password_hash, is_active) VALUES (:email, :hash, 1)'
        )->execute([
            ':email' => $email,
            ':hash'  => password_hash('secret', PASSWORD_BCRYPT),
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    private function buildSqlitePdo(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $pdo->exec("CREATE TABLE users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            email TEXT NOT NULL UNIQUE,
            password_hash TEXT NOT NULL,
            is_active INTEGER NOT NULL DEFAULT 1
        )");
        $pdo->exec("CREATE TABLE employee_profiles (
            user_id INTEGER NOT NULL PRIMARY KEY,
            display_name TEXT NOT NULL DEFAULT ''
        )");
        $pdo->exec("CREATE TABLE export_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            actor_user_id INTEGER NOT NULL,
            export_type TEXT NOT NULL,
            scope TEXT NOT NULL,
            target_user_id INTEGER NULL,
            month TEXT NOT NULL,
            row_count INTEGER NOT NULL DEFAULT 0,
            status TEXT NOT NULL DEFAULT 'ok',
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )");

        return $pdo;
    }

    private function injectPdo(PDO $pdo): void
    {
        $ref = new ReflectionProperty(\App\Db\Db::class, 'instance');
        $ref->setAccessible(true);
        $ref->setValue(null, $pdo);
    }

    private function resetDb(): void
    {
        $ref = new ReflectionProperty(\App\Db\Db::class, 'instance');
        $ref->setAccessible(true);
        $ref->setValue(null, null);
    }
}
