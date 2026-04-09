<?php

declare(strict_types=1);

namespace App\Tests\Feature\Timer;

use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * TM4.2 – Timer endpoint tests.
 *
 * Tests:
 *   1. 2x POST /timer/start ⇒ exactly 1 running session (idempotency)
 *   2. Double pause ⇒ no-op (still 1 paused session)
 *   3. Double resume ⇒ no-op (still 1 running session)
 *   4. Double stop ⇒ no-op
 *   5. Unauthenticated start ⇒ 403
 *   6. Missing CSRF ⇒ 403
 *
 * Uses an in-memory SQLite database injected via reflection into Db::$instance.
 */
class TimerTest extends TestCase
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
    // TM4.2 Must-have: 2x start ⇒ exactly 1 running session
    // =========================================================================

    public function testDoubleStartProducesExactlyOneRunningSession(): void
    {
        $userId = $this->createUser('emp@example.com');
        $token  = bin2hex(random_bytes(16));

        $session = [
            'user_id'      => $userId,
            '__user_roles' => ['employee'],
            '__csrf_token' => $token,
        ];

        // First start
        $result1 = dispatch('POST', '/timer/start', ['csrf_token' => $token], $session);
        $this->assertSame(303, $result1['status']);

        // Second start (idempotent)
        $result2 = dispatch('POST', '/timer/start', ['csrf_token' => $token], $session);
        $this->assertSame(303, $result2['status']);

        // Exactly 1 running session in DB
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM timer_sessions WHERE user_id = :uid AND status = 'running'"
        );
        $stmt->execute([':uid' => $userId]);
        $this->assertSame(1, (int) $stmt->fetchColumn());
    }

    // =========================================================================
    // Flash message on double start
    // =========================================================================

    public function testSecondStartSetsAlreadyRunningFlash(): void
    {
        $userId = $this->createUser('emp2@example.com');
        $token  = bin2hex(random_bytes(16));

        $session = [
            'user_id'      => $userId,
            '__user_roles' => ['employee'],
            '__csrf_token' => $token,
        ];

        dispatch('POST', '/timer/start', ['csrf_token' => $token], $session);
        $result = dispatch('POST', '/timer/start', ['csrf_token' => $token], $session);

        $flash = $result['session']['__flash'] ?? [];
        $this->assertContains('Timer läuft bereits.', $flash['success'] ?? []);
    }

    // =========================================================================
    // Double pause ⇒ no-op
    // =========================================================================

    public function testDoublePauseIsNoOp(): void
    {
        $userId = $this->createUser('emp3@example.com');
        $token  = bin2hex(random_bytes(16));

        $session = [
            'user_id'      => $userId,
            '__user_roles' => ['employee'],
            '__csrf_token' => $token,
        ];

        dispatch('POST', '/timer/start', ['csrf_token' => $token], $session);
        dispatch('POST', '/timer/pause', ['csrf_token' => $token], $session);
        $result = dispatch('POST', '/timer/pause', ['csrf_token' => $token], $session);

        $this->assertSame(303, $result['status']);

        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM timer_sessions WHERE user_id = :uid AND status = 'paused'"
        );
        $stmt->execute([':uid' => $userId]);
        $this->assertSame(1, (int) $stmt->fetchColumn());
    }

    // =========================================================================
    // Double resume ⇒ no-op
    // =========================================================================

    public function testDoubleResumeIsNoOp(): void
    {
        $userId = $this->createUser('emp4@example.com');
        $token  = bin2hex(random_bytes(16));

        $session = [
            'user_id'      => $userId,
            '__user_roles' => ['employee'],
            '__csrf_token' => $token,
        ];

        dispatch('POST', '/timer/start', ['csrf_token' => $token], $session);
        dispatch('POST', '/timer/pause', ['csrf_token' => $token], $session);
        dispatch('POST', '/timer/resume', ['csrf_token' => $token], $session);
        $result = dispatch('POST', '/timer/resume', ['csrf_token' => $token], $session);

        $this->assertSame(303, $result['status']);

        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM timer_sessions WHERE user_id = :uid AND status = 'running'"
        );
        $stmt->execute([':uid' => $userId]);
        $this->assertSame(1, (int) $stmt->fetchColumn());
    }

    // =========================================================================
    // Double stop ⇒ no-op
    // =========================================================================

    public function testDoubleStopIsNoOp(): void
    {
        $userId = $this->createUser('emp5@example.com');
        $token  = bin2hex(random_bytes(16));

        $session = [
            'user_id'      => $userId,
            '__user_roles' => ['employee'],
            '__csrf_token' => $token,
        ];

        dispatch('POST', '/timer/start', ['csrf_token' => $token], $session);
        dispatch('POST', '/timer/stop', ['csrf_token' => $token], $session);
        $result = dispatch('POST', '/timer/stop', ['csrf_token' => $token], $session);

        $this->assertSame(303, $result['status']);

        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM timer_sessions WHERE user_id = :uid AND status = 'stopped'"
        );
        $stmt->execute([':uid' => $userId]);
        $this->assertSame(1, (int) $stmt->fetchColumn());
    }

    // =========================================================================
    // Unauthenticated ⇒ 403
    // =========================================================================

    public function testUnauthenticatedStartReturns403(): void
    {
        $result = dispatch('POST', '/timer/start', ['csrf_token' => 'x'], []);
        $this->assertSame(403, $result['status']);
    }

    // =========================================================================
    // Missing CSRF ⇒ 403
    // =========================================================================

    public function testMissingCsrfReturns403(): void
    {
        $userId = $this->createUser('emp6@example.com');

        $result = dispatch('POST', '/timer/start', [], [
            'user_id'      => $userId,
            '__user_roles' => ['employee'],
            '__csrf_token' => 'correct-token',
        ]);

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

        $pdo->exec("
            CREATE TABLE users (
                id            INTEGER PRIMARY KEY AUTOINCREMENT,
                email         TEXT    NOT NULL UNIQUE,
                password_hash TEXT    NOT NULL,
                is_active     INTEGER NOT NULL DEFAULT 1
            )
        ");

        $pdo->exec("
            CREATE TABLE roles (
                id    INTEGER PRIMARY KEY AUTOINCREMENT,
                `key` TEXT NOT NULL UNIQUE,
                name  TEXT NOT NULL
            )
        ");

        $pdo->exec("
            CREATE TABLE user_roles (
                user_id INTEGER NOT NULL,
                role_id INTEGER NOT NULL,
                PRIMARY KEY (user_id, role_id)
            )
        ");

        $pdo->exec("
            CREATE TABLE timer_sessions (
                id                   INTEGER  PRIMARY KEY AUTOINCREMENT,
                user_id              INTEGER  NOT NULL,
                status               TEXT     NOT NULL DEFAULT 'running',
                started_at           TEXT     NOT NULL,
                paused_at            TEXT     NULL,
                stopped_at           TEXT     NULL,
                total_pause_seconds  INTEGER  NOT NULL DEFAULT 0,
                created_at           TEXT     NOT NULL DEFAULT (datetime('now')),
                updated_at           TEXT     NOT NULL DEFAULT (datetime('now'))
            )
        ");

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
