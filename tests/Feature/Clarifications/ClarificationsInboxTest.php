<?php

declare(strict_types=1);

namespace App\Tests\Feature\Clarifications;

use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Q3.7 – Employee Clarifications Inbox (GET /clarifications).
 *
 * Tests:
 *   1. Must-have: coordination role GET → 403.
 *   2. Employee GET → 200, shows own clarifications (open first).
 *   3. Unauthenticated → 403.
 */
class ClarificationsInboxTest extends TestCase
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
    // Must-have: coordination role GET → 403
    // =========================================================================

    public function testCoordinationGetReturns403(): void
    {
        $coordId = $this->createUser('coord@example.com');

        $result = dispatch(
            'GET',
            '/clarifications',
            [],
            ['user_id' => $coordId, '__user_roles' => ['coordination']],
        );

        $this->assertSame(403, $result['status']);
    }

    // =========================================================================
    // Employee GET → 200 with own clarifications, open first
    // =========================================================================

    public function testEmployeeGetReturns200WithOwnClarifications(): void
    {
        $coordId = $this->createUser('coord2@example.com');
        $empId   = $this->createUser('emp@example.com');
        $entryId = $this->insertTimeEntry($empId, 'in_clarification');

        // Insert answered clarification first (older), then open (newer)
        $this->insertClarification($entryId, $coordId, 'answered', '2026-04-09 08:00:00');
        $this->insertClarification($entryId, $coordId, 'open',     '2026-04-09 09:00:00');

        $result = dispatch(
            'GET',
            '/clarifications',
            [],
            ['user_id' => $empId, '__user_roles' => ['employee']],
        );

        $this->assertSame(200, $result['status']);
        $this->assertStringContainsString('Rückfragen', $result['body']);

        // Open entry must appear before answered entry in the output
        $posOpen     = strpos($result['body'], 'Offen');
        $posAnswered = strpos($result['body'], 'Beantwortet');
        $this->assertNotFalse($posOpen);
        $this->assertNotFalse($posAnswered);
        $this->assertLessThan($posAnswered, $posOpen, 'Open clarification must appear before answered.');
    }

    // =========================================================================
    // Unauthenticated → 403
    // =========================================================================

    public function testUnauthenticatedGetReturns403(): void
    {
        $result = dispatch('GET', '/clarifications', [], []);

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

    private function insertClarification(
        int    $entryId,
        int    $askedBy,
        string $status,
        string $createdAt = '2026-04-09 10:00:00',
    ): int {
        $this->pdo->prepare(
            'INSERT INTO clarifications
                 (time_entry_id, asked_by_user_id, question_text, status, created_at)
             VALUES
                 (:entry_id, :asked_by, :question, :status, :created_at)'
        )->execute([
            ':entry_id'   => $entryId,
            ':asked_by'   => $askedBy,
            ':question'   => 'Bitte erläutern.',
            ':status'     => $status,
            ':created_at' => $createdAt,
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
