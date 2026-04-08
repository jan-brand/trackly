<?php

declare(strict_types=1);

namespace App\Tests\Feature\TimeEntries;

use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * T2.6 – Time Entries E2E core invariants.
 *
 * Tests:
 *   1. approved → update ⇒ pending_approval
 *   2. cancel terminal: after cancel, update attempt ⇒ 400
 *   3. ownership: user A cannot view user B's entry ⇒ 403
 *
 * Uses an in-memory SQLite database injected via reflection into Db::instance.
 * All requests go through the full application router (simulateRequest / dispatch).
 */
class TimeEntriesE2ETest extends TestCase
{
    private PDO $pdo;

    // -------------------------------------------------------------------------
    // Set-up / tear-down
    // -------------------------------------------------------------------------

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
    // T2.6 Test 1: approved → update ⇒ pending_approval
    // =========================================================================

    public function testApprovedEntryBecomesOwnerApprovalAfterUpdate(): void
    {
        $userId = $this->createUser('emp1@example.com');
        $token  = bin2hex(random_bytes(16));

        // Create entry and force status to 'approved'
        $entryId = $this->insertTimeEntry($userId, 'approved');

        // POST update with valid input
        $result = simulateRequest(
            'POST',
            '/time-entries/' . $entryId,
            [
                'csrf_token'    => $token,
                'date'          => '2026-04-08',
                'start_time'    => '09:00',
                'end_time'      => '17:00',
                'break_minutes' => '30',
                'reason'        => 'Correcting approved entry',
            ],
            [],
            [
                'user_id'        => $userId,
                '__user_roles'   => ['employee'],
                '__csrf_token'   => $token,
            ],
        );

        // Expect 303 redirect on success
        $this->assertSame(303, $result['status']);

        // Verify status changed to pending_approval
        $stmt = $this->pdo->prepare('SELECT status FROM time_entries WHERE id = :id');
        $stmt->execute([':id' => $entryId]);
        $status = $stmt->fetchColumn();

        $this->assertSame('pending_approval', $status);
    }

    // =========================================================================
    // T2.6 Test 2: cancel terminal – further update ⇒ 400
    // =========================================================================

    public function testCancelTerminalAndUpdateAttemptReturns400(): void
    {
        $userId = $this->createUser('emp2@example.com');
        $token  = bin2hex(random_bytes(16));

        $entryId = $this->insertTimeEntry($userId, 'pending_approval');

        // Step 1: cancel the entry
        $cancelResult = simulateRequest(
            'POST',
            '/time-entries/' . $entryId . '/cancel',
            [
                'csrf_token' => $token,
                'reason'     => 'Entry was created in error',
            ],
            [],
            [
                'user_id'       => $userId,
                '__user_roles'  => ['employee'],
                '__csrf_token'  => $token,
            ],
        );

        $this->assertSame(303, $cancelResult['status']);

        // Verify status is cancelled
        $stmt = $this->pdo->prepare('SELECT status FROM time_entries WHERE id = :id');
        $stmt->execute([':id' => $entryId]);
        $this->assertSame('cancelled', $stmt->fetchColumn());

        // Step 2: try to update the cancelled entry → expect 400
        $updateResult = simulateRequest(
            'POST',
            '/time-entries/' . $entryId,
            [
                'csrf_token'    => $token,
                'date'          => '2026-04-08',
                'start_time'    => '09:00',
                'end_time'      => '17:00',
                'break_minutes' => '30',
                'reason'        => 'Attempting to update cancelled entry',
            ],
            [],
            [
                'user_id'       => $userId,
                '__user_roles'  => ['employee'],
                '__csrf_token'  => $token,
            ],
        );

        $this->assertSame(400, $updateResult['status']);
    }

    // =========================================================================
    // T2.6 Test 3: ownership — user A cannot view user B's entry ⇒ 403
    // =========================================================================

    public function testOwnershipUserACannotViewUserBEntry(): void
    {
        $userA = $this->createUser('userA@example.com');
        $userB = $this->createUser('userB@example.com');

        // Create an entry that belongs to user B
        $entryId = $this->insertTimeEntry($userB, 'pending_approval');

        // User A tries to load user B's entry
        $result = simulateRequest(
            'GET',
            '/time-entries/' . $entryId,
            [],
            [],
            [
                'user_id'       => $userA,
                '__user_roles'  => ['employee'],
            ],
        );

        $this->assertSame(403, $result['status']);
    }

    // =========================================================================
    // T2.5 must-have: unauthenticated access ⇒ 403
    // =========================================================================

    public function testUnauthenticatedAccessReturns403(): void
    {
        $result = simulateRequest('GET', '/time-entries', [], [], []);
        $this->assertSame(403, $result['status']);
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    private function createUser(string $email): int
    {
        $this->pdo->prepare(
            'INSERT INTO users (email, password_hash, is_active)
             VALUES (:email, :hash, 1)'
        )->execute([
            ':email' => $email,
            ':hash'  => password_hash('secret', PASSWORD_BCRYPT),
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    private function insertTimeEntry(int $userId, string $status = 'pending'): int
    {
        $this->pdo->prepare(
            "INSERT INTO time_entries
                 (user_id, date_local, start_at, end_at, break_minutes, net_minutes,
                  entry_source, status, created_at, updated_at)
             VALUES
                 (:user_id, '2026-04-08', '2026-04-08 09:00:00', '2026-04-08 17:00:00',
                  30, 450, 'manual', :status, '2026-04-08 08:00:00', '2026-04-08 08:00:00')"
        )->execute([':user_id' => $userId, ':status' => $status]);

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
                id   INTEGER PRIMARY KEY AUTOINCREMENT,
                `key` TEXT NOT NULL UNIQUE,
                name TEXT NOT NULL
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
            CREATE TABLE settings (
                `key`              TEXT    NOT NULL PRIMARY KEY,
                `value_json`       TEXT    NOT NULL,
                `updated_by_user_id` INTEGER NULL,
                `updated_at`       TEXT    NOT NULL
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
