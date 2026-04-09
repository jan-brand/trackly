<?php

declare(strict_types=1);

namespace App\Tests\Feature\Timer;

use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * TM4.2 – Timer endpoint tests.
 * TM4.5 – Multi-tab, stop-from-paused, and idempotent-stop tests.
 *
 * Tests:
 *   1. 2x POST /timer/start ⇒ exactly 1 running session (idempotency)
 *   2. Double pause ⇒ no-op (still 1 paused session)
 *   3. Double resume ⇒ no-op (still 1 running session)
 *   4. Double stop ⇒ no-op
 *   5. Unauthenticated start ⇒ 403
 *   6. Missing CSRF ⇒ 403
 *   7. (TM4.5) Multi-tab: 2x start → 1 running session
 *   8. (TM4.5) Stop-from-paused: net_minutes < shift_minutes
 *   9. (TM4.5) Idempotent stop: 2x stop → only 1 time_entry
 *  10. (TM4.4) Non-employee GET /timer ⇒ 403
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
    // TM4.4 Must-have: unauthenticated GET /timer ⇒ 403
    // =========================================================================

    public function testUnauthenticatedGetTimerReturns403(): void
    {
        $result = dispatch('GET', '/timer', [], []);

        $this->assertSame(403, $result['status']);
    }

    // =========================================================================
    // TM4.5 Test 1: Multi-tab – 2x start ⇒ exactly 1 running session
    // (already covered by testDoubleStartProducesExactlyOneRunningSession,
    //  but included here explicitly as TM4.5 multi-tab requirement)
    // =========================================================================

    public function testMultiTabDoubleStartProducesExactlyOneRunningSession(): void
    {
        $userId = $this->createUser('mt@example.com');
        $token  = bin2hex(random_bytes(16));

        $session = [
            'user_id'      => $userId,
            '__user_roles' => ['employee'],
            '__csrf_token' => $token,
        ];

        dispatch('POST', '/timer/start', ['csrf_token' => $token], $session);
        dispatch('POST', '/timer/start', ['csrf_token' => $token], $session);

        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM timer_sessions WHERE user_id = :uid AND status = 'running'"
        );
        $stmt->execute([':uid' => $userId]);
        $this->assertSame(1, (int) $stmt->fetchColumn());
    }

    // =========================================================================
    // TM4.5 Test 2: Stop-from-paused → net_minutes < shift_minutes
    //
    // Strategy: insert a timer_session with a past started_at and paused_at so
    // that the net duration is deterministic regardless of when the test runs.
    //
    //   started_at  = 2026-01-01 09:00:00
    //   paused_at   = 2026-01-01 09:30:00  (paused after 30 min of work)
    //   total_pause_seconds = 0
    //
    // When stopped at "now" (any future time):
    //   net_seconds = paused_at − started_at = 1800 s → net_minutes = 30
    //   shift_seconds >> 1800 s → shift_minutes >> 30
    //   ⇒ net_minutes (30) < shift_minutes (very large)
    // =========================================================================

    public function testStopFromPausedNetMinutesAccountsForPause(): void
    {
        $userId = $this->createUser('paus@example.com');
        $token  = bin2hex(random_bytes(16));

        $this->insertTimerSession($userId, 'paused', '2026-01-01 09:00:00', '2026-01-01 09:30:00', 0);

        $result = dispatch('POST', '/timer/stop', ['csrf_token' => $token], [
            'user_id'      => $userId,
            '__user_roles' => ['employee'],
            '__csrf_token' => $token,
        ]);

        $this->assertSame(303, $result['status']);

        $stmt = $this->pdo->prepare(
            'SELECT net_minutes, break_minutes FROM time_entries WHERE user_id = :uid LIMIT 1'
        );
        $stmt->execute([':uid' => $userId]);
        $row = $stmt->fetch();

        $this->assertNotFalse($row, 'A time_entry must be created on stop.');

        $netMinutes   = (int) $row['net_minutes'];
        $shiftMinutes = $netMinutes + (int) $row['break_minutes'];

        $this->assertSame(30, $netMinutes, 'net_minutes should equal the 30 min of actual work before pause.');
        $this->assertGreaterThan($netMinutes, $shiftMinutes, 'shift_minutes must exceed net_minutes because of pause time.');
    }

    // =========================================================================
    // TM4.5 Test 3: Idempotent stop – 2x stop ⇒ exactly 1 time_entry created
    // =========================================================================

    public function testIdempotentStopCreatesExactlyOneTimeEntry(): void
    {
        $userId = $this->createUser('idem@example.com');
        $token  = bin2hex(random_bytes(16));

        $session = [
            'user_id'      => $userId,
            '__user_roles' => ['employee'],
            '__csrf_token' => $token,
        ];

        // Start the timer
        dispatch('POST', '/timer/start', ['csrf_token' => $token], $session);

        // First stop → should create a time_entry
        $result1 = dispatch('POST', '/timer/stop', ['csrf_token' => $token], $session);
        $this->assertSame(303, $result1['status']);

        // Second stop → no-op
        $result2 = dispatch('POST', '/timer/stop', ['csrf_token' => $token], $session);
        $this->assertSame(303, $result2['status']);

        // Exactly 1 time_entry must exist
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM time_entries WHERE user_id = :uid');
        $stmt->execute([':uid' => $userId]);
        $this->assertSame(1, (int) $stmt->fetchColumn(), 'Second stop must not create another time_entry.');
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

    private function insertTimerSession(
        int $userId,
        string $status,
        string $startedAt,
        ?string $pausedAt,
        int $totalPauseSecs,
    ): int {
        $this->pdo->prepare(
            "INSERT INTO timer_sessions
                 (user_id, status, started_at, paused_at, total_pause_seconds)
             VALUES
                 (:user_id, :status, :started_at, :paused_at, :total_pause_seconds)"
        )->execute([
            ':user_id'             => $userId,
            ':status'              => $status,
            ':started_at'          => $startedAt,
            ':paused_at'           => $pausedAt,
            ':total_pause_seconds' => $totalPauseSecs,
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

        $pdo->exec("
            CREATE TABLE time_entries (
                id                    INTEGER  PRIMARY KEY AUTOINCREMENT,
                user_id               INTEGER  NOT NULL,
                date_local            TEXT     NOT NULL,
                start_at              TEXT     NOT NULL,
                end_at                TEXT     NOT NULL,
                break_minutes         INTEGER  NOT NULL DEFAULT 0,
                net_minutes           INTEGER  NOT NULL,
                entry_source          TEXT     NOT NULL DEFAULT 'manual',
                status                TEXT     NOT NULL DEFAULT 'pending',
                approved_by_user_id   INTEGER  NULL,
                approved_at           TEXT     NULL,
                cancelled_by_user_id  INTEGER  NULL,
                cancelled_at          TEXT     NULL,
                created_at            TEXT     NOT NULL,
                updated_at            TEXT     NOT NULL
            )
        ");

        $pdo->exec("
            CREATE TABLE time_entry_flags (
                id             INTEGER PRIMARY KEY AUTOINCREMENT,
                time_entry_id  INTEGER NOT NULL,
                flag_key       TEXT    NOT NULL,
                flag_value     TEXT    NULL,
                sort_index     INTEGER NOT NULL DEFAULT 0,
                created_at     TEXT    NOT NULL,
                UNIQUE (time_entry_id, flag_key)
            )
        ");

        $pdo->exec("
            CREATE TABLE time_entry_audit_log (
                id             INTEGER PRIMARY KEY AUTOINCREMENT,
                time_entry_id  INTEGER NOT NULL,
                actor_user_id  INTEGER NOT NULL,
                action         TEXT    NOT NULL,
                reason         TEXT    NOT NULL,
                old_json       TEXT    NULL,
                new_json       TEXT    NOT NULL,
                created_at     TEXT    NOT NULL
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
