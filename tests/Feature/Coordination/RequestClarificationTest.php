<?php

declare(strict_types=1);

namespace App\Tests\Feature\Coordination;

use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Q3.2 – request-clarification endpoint.
 *
 * Tests:
 *   1. request_clarification creates 1 clarification(open) and sets status=in_clarification
 *   2. Missing / too-short question_text ⇒ 400
 *   3. Unauthenticated ⇒ 403
 *   4. Employee role (no coordination/admin) ⇒ 403
 */
class RequestClarificationTest extends TestCase
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
    // Must-have: creates 1 clarification(open) and sets status=in_clarification
    // =========================================================================

    public function testRequestClarificationCreatesOpenClarificationAndSetsStatus(): void
    {
        $coordId = $this->createUser('coord@example.com');
        $empId   = $this->createUser('emp@example.com');
        $entryId = $this->insertTimeEntry($empId, 'pending_approval');
        $token   = bin2hex(random_bytes(16));

        $result = dispatch(
            'POST',
            '/coordination/time-entries/' . $entryId . '/request-clarification',
            [
                'csrf_token'    => $token,
                'question_text' => 'Bitte Beleg einreichen.',
            ],
            [
                'user_id'      => $coordId,
                '__user_roles' => ['coordination'],
                '__csrf_token' => $token,
            ],
        );

        $this->assertSame(303, $result['status']);
        $this->assertSame('/time-entries/' . $entryId, $result['headers']['Location']);

        // Exactly one clarification row with status=open
        $stmt = $this->pdo->prepare(
            'SELECT * FROM clarifications WHERE time_entry_id = :id'
        );
        $stmt->execute([':id' => $entryId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $this->assertCount(1, $rows);
        $this->assertSame('open', $rows[0]['status']);
        $this->assertSame('Bitte Beleg einreichen.', $rows[0]['question_text']);
        $this->assertSame((string) $coordId, (string) $rows[0]['asked_by_user_id']);

        // time_entry status must be in_clarification
        $stmt = $this->pdo->prepare('SELECT status FROM time_entries WHERE id = :id');
        $stmt->execute([':id' => $entryId]);
        $this->assertSame('in_clarification', $stmt->fetchColumn());

        // One audit log row with action=request_clarification
        $stmt = $this->pdo->prepare(
            "SELECT * FROM time_entry_audit_log WHERE time_entry_id = :id AND action = 'request_clarification'"
        );
        $stmt->execute([':id' => $entryId]);
        $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $this->assertCount(1, $logs);
        $this->assertSame('Bitte Beleg einreichen.', $logs[0]['reason']);
    }

    // =========================================================================
    // question_text too short ⇒ 400
    // =========================================================================

    public function testShortQuestionTextReturns400(): void
    {
        $coordId = $this->createUser('coord2@example.com');
        $empId   = $this->createUser('emp2@example.com');
        $entryId = $this->insertTimeEntry($empId, 'pending_approval');
        $token   = bin2hex(random_bytes(16));

        $result = dispatch(
            'POST',
            '/coordination/time-entries/' . $entryId . '/request-clarification',
            [
                'csrf_token'    => $token,
                'question_text' => 'abc',
            ],
            [
                'user_id'      => $coordId,
                '__user_roles' => ['coordination'],
                '__csrf_token' => $token,
            ],
        );

        $this->assertSame(400, $result['status']);
    }

    // =========================================================================
    // Unauthenticated ⇒ 403
    // =========================================================================

    public function testUnauthenticatedReturns403(): void
    {
        $empId   = $this->createUser('emp3@example.com');
        $entryId = $this->insertTimeEntry($empId, 'pending_approval');
        $token   = bin2hex(random_bytes(16));

        $result = dispatch(
            'POST',
            '/coordination/time-entries/' . $entryId . '/request-clarification',
            ['csrf_token' => $token, 'question_text' => 'Bitte Beleg einreichen.'],
            [],
        );

        $this->assertSame(403, $result['status']);
    }

    // =========================================================================
    // Employee role (no coordination/admin) ⇒ 403
    // =========================================================================

    public function testEmployeeRoleReturns403(): void
    {
        $empId   = $this->createUser('emp4@example.com');
        $entryId = $this->insertTimeEntry($empId, 'pending_approval');
        $token   = bin2hex(random_bytes(16));

        $result = dispatch(
            'POST',
            '/coordination/time-entries/' . $entryId . '/request-clarification',
            ['csrf_token' => $token, 'question_text' => 'Bitte Beleg einreichen.'],
            [
                'user_id'      => $empId,
                '__user_roles' => ['employee'],
                '__csrf_token' => $token,
            ],
        );

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
                 (:user_id, '2026-04-09', '2026-04-09 09:00:00', '2026-04-09 17:00:00',
                  30, 450, 'manual', :status, '2026-04-09 08:00:00', '2026-04-09 08:00:00')"
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
            CREATE TABLE settings (
                `key`               TEXT NOT NULL PRIMARY KEY,
                `value_json`        TEXT NOT NULL,
                `updated_by_user_id` INTEGER NULL,
                `updated_at`        TEXT NOT NULL
            )
        ");

        $pdo->exec("
            CREATE TABLE time_entries (
                id                    INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id               INTEGER NOT NULL,
                date_local            TEXT    NOT NULL,
                start_at              TEXT    NOT NULL,
                end_at                TEXT    NOT NULL,
                break_minutes         INTEGER NOT NULL DEFAULT 0,
                net_minutes           INTEGER NOT NULL,
                entry_source          TEXT    NOT NULL DEFAULT 'manual',
                status                TEXT    NOT NULL DEFAULT 'pending',
                approved_by_user_id   INTEGER NULL,
                approved_at           TEXT    NULL,
                cancelled_by_user_id  INTEGER NULL,
                cancelled_at          TEXT    NULL,
                created_at            TEXT    NOT NULL,
                updated_at            TEXT    NOT NULL
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

        $pdo->exec("
            CREATE TABLE clarifications (
                id                   INTEGER PRIMARY KEY AUTOINCREMENT,
                time_entry_id        INTEGER NOT NULL,
                asked_by_user_id     INTEGER NOT NULL,
                question_text        TEXT    NOT NULL,
                status               TEXT    NOT NULL DEFAULT 'open',
                answered_by_user_id  INTEGER NULL,
                answer_text          TEXT    NULL,
                created_at           TEXT    NOT NULL,
                answered_at          TEXT    NULL
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
