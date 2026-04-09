<?php

declare(strict_types=1);

namespace App\Tests\Feature\Coordination;

use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Q3.3 – Answer Clarification.
 *
 * Tests:
 *   1. Must-have: last open clarification answered → target.status = pending_approval
 *   2. Two open clarifications: after first answer target stays in_clarification
 *   3. Clarification not open → 400
 *   4. Employee cannot answer another user's clarification → 403
 *   5. Employee can answer their own entry's clarification → 303
 *   6. Missing / too-short answer_text → 400
 */
class AnswerClarificationTest extends TestCase
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
    // Must-have: last open answered → target.status = pending_approval
    // =========================================================================

    public function testLastOpenAnsweredSetsTargetToPendingApproval(): void
    {
        $coordId = $this->createUser('coord@example.com');
        $empId   = $this->createUser('emp@example.com');
        $entryId = $this->insertTimeEntry($empId, 'in_clarification');
        $clarId  = $this->insertClarification($entryId, $coordId, 'open');
        $token   = bin2hex(random_bytes(16));

        $result = dispatch(
            'POST',
            '/clarifications/' . $clarId . '/answer',
            ['csrf_token' => $token, 'answer_text' => 'Beleg liegt vor.'],
            ['user_id' => $empId, '__user_roles' => ['employee'], '__csrf_token' => $token],
        );

        $this->assertSame(303, $result['status']);
        $this->assertSame('/clarifications', $result['headers']['Location']);

        // clarification must be answered
        $stmt = $this->pdo->prepare('SELECT status, answer_text FROM clarifications WHERE id = :id');
        $stmt->execute([':id' => $clarId]);
        $clar = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('answered', $clar['status']);
        $this->assertSame('Beleg liegt vor.', $clar['answer_text']);

        // target must be pending_approval (no more open clarifications)
        $stmt = $this->pdo->prepare('SELECT status FROM time_entries WHERE id = :id');
        $stmt->execute([':id' => $entryId]);
        $this->assertSame('pending_approval', $stmt->fetchColumn());

        // audit log entry
        $stmt = $this->pdo->prepare(
            "SELECT reason FROM time_entry_audit_log
              WHERE time_entry_id = :id AND action = 'answer_clarification'"
        );
        $stmt->execute([':id' => $entryId]);
        $this->assertSame('Beleg liegt vor.', $stmt->fetchColumn());
    }

    // =========================================================================
    // Two open clarifications: after first answer target stays in_clarification
    // =========================================================================

    public function testWithTwoOpenClarificationsTargetRemainsInClarification(): void
    {
        $coordId  = $this->createUser('coord2@example.com');
        $empId    = $this->createUser('emp2@example.com');
        $entryId  = $this->insertTimeEntry($empId, 'in_clarification');
        $clarId1  = $this->insertClarification($entryId, $coordId, 'open');
        $this->insertClarification($entryId, $coordId, 'open'); // second open
        $token    = bin2hex(random_bytes(16));

        $result = dispatch(
            'POST',
            '/clarifications/' . $clarId1 . '/answer',
            ['csrf_token' => $token, 'answer_text' => 'Antwort 1'],
            ['user_id' => $empId, '__user_roles' => ['employee'], '__csrf_token' => $token],
        );

        $this->assertSame(303, $result['status']);

        // target must still be in_clarification
        $stmt = $this->pdo->prepare('SELECT status FROM time_entries WHERE id = :id');
        $stmt->execute([':id' => $entryId]);
        $this->assertSame('in_clarification', $stmt->fetchColumn());
    }

    // =========================================================================
    // Already answered clarification → 400
    // =========================================================================

    public function testAlreadyAnsweredClarificationReturns400(): void
    {
        $coordId = $this->createUser('coord3@example.com');
        $empId   = $this->createUser('emp3@example.com');
        $entryId = $this->insertTimeEntry($empId, 'pending_approval');
        $clarId  = $this->insertClarification($entryId, $coordId, 'answered');
        $token   = bin2hex(random_bytes(16));

        $result = dispatch(
            'POST',
            '/clarifications/' . $clarId . '/answer',
            ['csrf_token' => $token, 'answer_text' => 'Nochmal'],
            ['user_id' => $empId, '__user_roles' => ['employee'], '__csrf_token' => $token],
        );

        $this->assertSame(400, $result['status']);
    }

    // =========================================================================
    // Employee cannot answer another user's clarification → 403
    // =========================================================================

    public function testEmployeeCannotAnswerOtherUsersClarification(): void
    {
        $coordId  = $this->createUser('coord4@example.com');
        $empA     = $this->createUser('empA@example.com');
        $empB     = $this->createUser('empB@example.com');
        $entryId  = $this->insertTimeEntry($empA, 'in_clarification');
        $clarId   = $this->insertClarification($entryId, $coordId, 'open');
        $token    = bin2hex(random_bytes(16));

        $result = dispatch(
            'POST',
            '/clarifications/' . $clarId . '/answer',
            ['csrf_token' => $token, 'answer_text' => 'Hacked'],
            ['user_id' => $empB, '__user_roles' => ['employee'], '__csrf_token' => $token],
        );

        $this->assertSame(403, $result['status']);
    }

    // =========================================================================
    // answer_text too short → 400
    // =========================================================================

    public function testShortAnswerTextReturns400(): void
    {
        $coordId = $this->createUser('coord5@example.com');
        $empId   = $this->createUser('emp5@example.com');
        $entryId = $this->insertTimeEntry($empId, 'in_clarification');
        $clarId  = $this->insertClarification($entryId, $coordId, 'open');
        $token   = bin2hex(random_bytes(16));

        $result = dispatch(
            'POST',
            '/clarifications/' . $clarId . '/answer',
            ['csrf_token' => $token, 'answer_text' => 'x'],
            ['user_id' => $empId, '__user_roles' => ['employee'], '__csrf_token' => $token],
        );

        $this->assertSame(400, $result['status']);
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

    private function insertTimeEntry(int $userId, string $status = 'pending'): int
    {
        $this->pdo->prepare(
            "INSERT INTO time_entries
                 (user_id, date_local, start_at, end_at, break_minutes, net_minutes,
                  entry_source, status, created_at, updated_at)
             VALUES
                 (:user_id, '2026-04-09', '2026-04-09 09:00:00', '2026-04-09 17:00:00',
                  30, 450, 'manual', :status, '2026-04-09 08:00:00', '2026-04-09 08:00:00')"
        )->execute([':user_id' => $userId, ':status' => $status]);

        return (int) $this->pdo->lastInsertId();
    }

    private function insertClarification(int $entryId, int $askedBy, string $status): int
    {
        $this->pdo->prepare(
            "INSERT INTO clarifications (time_entry_id, asked_by_user_id, question_text, status, created_at)
             VALUES (:entry_id, :asked_by, 'Bitte erläutern.', :status, '2026-04-09 10:00:00')"
        )->execute([
            ':entry_id' => $entryId,
            ':asked_by' => $askedBy,
            ':status'   => $status,
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

        $pdo->exec("CREATE TABLE roles (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            `key` TEXT NOT NULL UNIQUE,
            name TEXT NOT NULL
        )");

        $pdo->exec("CREATE TABLE user_roles (
            user_id INTEGER NOT NULL,
            role_id INTEGER NOT NULL,
            PRIMARY KEY (user_id, role_id)
        )");

        $pdo->exec("CREATE TABLE settings (
            `key` TEXT NOT NULL PRIMARY KEY,
            value_json TEXT NOT NULL,
            updated_by_user_id INTEGER NULL,
            updated_at TEXT NOT NULL
        )");

        $pdo->exec("CREATE TABLE time_entries (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            date_local TEXT NOT NULL,
            start_at TEXT NOT NULL,
            end_at TEXT NOT NULL,
            break_minutes INTEGER NOT NULL DEFAULT 0,
            net_minutes INTEGER NOT NULL,
            entry_source TEXT NOT NULL DEFAULT 'manual',
            status TEXT NOT NULL DEFAULT 'pending',
            approved_by_user_id INTEGER NULL,
            approved_at TEXT NULL,
            cancelled_by_user_id INTEGER NULL,
            cancelled_at TEXT NULL,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )");

        $pdo->exec("CREATE TABLE time_entry_flags (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            time_entry_id INTEGER NOT NULL,
            flag_key TEXT NOT NULL,
            flag_value TEXT NULL,
            sort_index INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL,
            UNIQUE (time_entry_id, flag_key)
        )");

        $pdo->exec("CREATE TABLE time_entry_audit_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            time_entry_id INTEGER NOT NULL,
            actor_user_id INTEGER NOT NULL,
            action TEXT NOT NULL,
            reason TEXT NOT NULL,
            old_json TEXT NULL,
            new_json TEXT NOT NULL,
            created_at TEXT NOT NULL
        )");

        $pdo->exec("CREATE TABLE clarifications (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            time_entry_id INTEGER NOT NULL,
            asked_by_user_id INTEGER NOT NULL,
            question_text TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT 'open',
            answered_by_user_id INTEGER NULL,
            answer_text TEXT NULL,
            created_at TEXT NOT NULL,
            answered_at TEXT NULL
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
