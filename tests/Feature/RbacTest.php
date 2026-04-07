<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Controllers\AuthController;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * R0.9 / R0.10 – RBAC guards and post-login navigation tests.
 */
class RbacTest extends TestCase
{
    private ?PDO $pdo = null;

    protected function setUp(): void
    {
        $_SESSION = [];
        $_POST    = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
    }

    protected function tearDown(): void
    {
        $this->resetDbInstance();

        $_SESSION = [];
        $_POST    = [];

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
    }

    // -------------------------------------------------------------------------
    // R0.9 – RBAC
    // -------------------------------------------------------------------------

    /**
     * An employee trying to access GET /admin/settings must receive HTTP 403.
     * The guard must reject the request without querying the DB when roles are
     * already present in the session cache.
     */
    public function testEmployeeCannotAccessAdminSettings(): void
    {
        $result = simulateRequest(
            'GET',
            '/admin/settings',
            [],
            [],
            [
                'user_id'       => 1,
                '__user_roles'  => ['employee'],
            ],
        );

        $this->assertSame(403, $result['status']);
    }

    // -------------------------------------------------------------------------
    // R0.10 – Default redirect after login
    // -------------------------------------------------------------------------

    /**
     * After a successful login with an admin account the redirect must point to
     * /coordination/queue.
     */
    public function testAdminLoginRedirectsToCoordinationQueue(): void
    {
        $this->injectSqliteDb();
        $userId = $this->createUser('admin@example.com', 'secret', active: true);
        $this->assignRole($userId, 'admin');

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $_SESSION = [];

        $token = $this->setCsrfToken();
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'email'      => 'admin@example.com',
            'password'   => 'secret',
            'csrf_token' => $token,
        ];

        $response = (new AuthController())->doLogin();

        $this->assertSame(303, $response->statusCode);
        $this->assertSame('/coordination/queue', $response->headers['Location']);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Inject an in-memory SQLite PDO with the minimal schema required by
     * Auth::roles() and AuthController::doLogin().
     */
    private function injectSqliteDb(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $this->pdo->exec(
            'CREATE TABLE users (
                id            INTEGER PRIMARY KEY AUTOINCREMENT,
                email         TEXT    NOT NULL UNIQUE,
                password_hash TEXT    NOT NULL,
                is_active     INTEGER NOT NULL DEFAULT 1
            )'
        );

        $this->pdo->exec(
            'CREATE TABLE roles (
                id   INTEGER PRIMARY KEY AUTOINCREMENT,
                `key` TEXT NOT NULL UNIQUE,
                name TEXT NOT NULL
            )'
        );

        $this->pdo->exec(
            'CREATE TABLE user_roles (
                user_id INTEGER NOT NULL,
                role_id INTEGER NOT NULL,
                PRIMARY KEY (user_id, role_id)
            )'
        );

        $ref = new ReflectionProperty(\App\Db\Db::class, 'instance');
        $ref->setAccessible(true);
        $ref->setValue(null, $this->pdo);
    }

    private function createUser(string $email, string $password, bool $active): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO users (email, password_hash, is_active) VALUES (:email, :hash, :active)'
        );
        $stmt->execute([
            ':email'  => $email,
            ':hash'   => password_hash($password, PASSWORD_BCRYPT),
            ':active' => $active ? 1 : 0,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    private function assignRole(int $userId, string $roleKey): void
    {
        $this->pdo->prepare(
            'INSERT INTO roles (`key`, name) VALUES (:key, :name)'
        )->execute([':key' => $roleKey, ':name' => $roleKey]);

        $roleId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare(
            'INSERT INTO user_roles (user_id, role_id) VALUES (:uid, :rid)'
        )->execute([':uid' => $userId, ':rid' => $roleId]);
    }

    private function setCsrfToken(): string
    {
        $token = bin2hex(random_bytes(16));
        $_SESSION['__csrf_token'] = $token;
        return $token;
    }

    private function resetDbInstance(): void
    {
        $ref = new ReflectionProperty(\App\Db\Db::class, 'instance');
        $ref->setAccessible(true);
        $ref->setValue(null, null);

        $this->pdo = null;
    }
}
