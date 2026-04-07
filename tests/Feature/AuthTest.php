<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Controllers\AuthController;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * R0.7 / R0.8 – Login, Logout & CSRF tests.
 *
 * Tests that need a database inject an in-memory SQLite PDO into Db::$instance
 * via reflection so that the controller can run without a real MySQL server.
 */
class AuthTest extends TestCase
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
    // R0.7 – Login
    // -------------------------------------------------------------------------

    /**
     * An inactive user (is_active = 0) must receive the generic error message
     * and a 422 status, regardless of whether the password is correct.
     */
    public function testInactiveUserLoginReturnsGenericError(): void
    {
        $this->injectSqliteDb();
        $this->createUser('inactive@example.com', 'secret', active: false);

        $token = $this->setCsrfToken();
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'email'      => 'inactive@example.com',
            'password'   => 'secret',
            'csrf_token' => $token,
        ];

        $response = (new AuthController())->doLogin();

        $this->assertSame(422, $response->statusCode);
        $this->assertStringContainsString(
            'E-Mail oder Passwort stimmt nicht.',
            $response->body,
        );
    }

    /**
     * A successful login must call session_regenerate_id(true), which changes
     * the session ID to prevent session-fixation attacks.
     */
    public function testLoginRegeneratesSessionId(): void
    {
        $this->injectSqliteDb();
        $this->createUser('active@example.com', 'secret', active: true);

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $_SESSION = [];

        $token = $this->setCsrfToken();
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'email'      => 'active@example.com',
            'password'   => 'secret',
            'csrf_token' => $token,
        ];

        $before = session_id();

        (new AuthController())->doLogin();

        $after = session_id();

        $this->assertNotSame('', $before, 'A PHP session must be active before login.');
        $this->assertNotSame(
            $before,
            $after,
            'session_regenerate_id(true) must change the session ID on successful login.',
        );
    }

    // -------------------------------------------------------------------------
    // R0.8 – CSRF
    // -------------------------------------------------------------------------

    /**
     * POST /logout without a CSRF token (or with a mismatching token) must
     * return HTTP 403 Forbidden.
     */
    public function testPostLogoutWithoutCsrfTokenReturnsForbidden(): void
    {
        $result = simulateRequest('POST', '/logout', []);

        $this->assertSame(403, $result['status']);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Replace the Db singleton with an in-memory SQLite connection that has
     * the minimal schema required by AuthController.
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

        $ref = new ReflectionProperty(\App\Db\Db::class, 'instance');
        $ref->setAccessible(true);
        $ref->setValue(null, $this->pdo);
    }

    private function createUser(string $email, string $password, bool $active): void
    {
        $this->pdo->prepare(
            'INSERT INTO users (email, password_hash, is_active) VALUES (:email, :hash, :active)'
        )->execute([
            ':email'  => $email,
            ':hash'   => password_hash($password, PASSWORD_BCRYPT),
            ':active' => $active ? 1 : 0,
        ]);
    }

    /**
     * Write a CSRF token into the session (matching Csrf::SESSION_KEY)
     * and return the token value so it can be posted back.
     */
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
