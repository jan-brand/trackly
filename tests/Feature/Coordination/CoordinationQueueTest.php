<?php

declare(strict_types=1);

namespace App\Tests\Feature\Coordination;

use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Q3.4 + Q3.4a – Coordination Queue GET endpoint.
 *
 * Tests:
 *   1. Must-have: employee GET → 403
 *   2. Must-have: unknown query param → 400
 *   3. coordination role → 200
 *   4. admin role → 200
 *   5. Invalid tab value → 400
 *   6. Invalid status value → 400
 *   7. Invalid sort value → 400
 *   8. Invalid month value → 400
 *   9. Q3.4a Must-have: tab=announcements → 200 + "Noch nicht implementiert."
 *  10. tab=announcements with unknown extra param → 400
 */
class CoordinationQueueTest extends TestCase
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
    // Must-have: employee GET → 403
    // =========================================================================

    public function testEmployeeGetReturns403(): void
    {
        $empId = $this->createUser('emp@example.com');

        $result = dispatch(
            'GET',
            '/coordination/queue',
            [],
            ['user_id' => $empId, '__user_roles' => ['employee']],
        );

        $this->assertSame(403, $result['status']);
    }

    // =========================================================================
    // Must-have: unknown param → 400
    // =========================================================================

    public function testUnknownParamReturns400(): void
    {
        $coordId = $this->createUser('coord@example.com');

        $result = dispatch(
            'GET',
            '/coordination/queue?unknown_param=foo',
            [],
            ['user_id' => $coordId, '__user_roles' => ['coordination']],
        );

        $this->assertSame(400, $result['status']);
    }

    // =========================================================================
    // coordination role → 200
    // =========================================================================

    public function testCoordinationRoleReturns200(): void
    {
        $coordId = $this->createUser('coord2@example.com');

        $result = dispatch(
            'GET',
            '/coordination/queue',
            [],
            ['user_id' => $coordId, '__user_roles' => ['coordination']],
        );

        $this->assertSame(200, $result['status']);
        $this->assertStringContainsString('Queue', $result['body']);
    }

    // =========================================================================
    // admin role → 200
    // =========================================================================

    public function testAdminRoleReturns200(): void
    {
        $adminId = $this->createUser('admin@example.com');

        $result = dispatch(
            'GET',
            '/coordination/queue',
            [],
            ['user_id' => $adminId, '__user_roles' => ['admin']],
        );

        $this->assertSame(200, $result['status']);
    }

    // =========================================================================
    // Invalid tab → 400
    // =========================================================================

    public function testInvalidTabReturns400(): void
    {
        $coordId = $this->createUser('coord3@example.com');

        $result = dispatch(
            'GET',
            '/coordination/queue?tab=invalid',
            [],
            ['user_id' => $coordId, '__user_roles' => ['coordination']],
        );

        $this->assertSame(400, $result['status']);
    }

    // =========================================================================
    // Invalid status → 400
    // =========================================================================

    public function testInvalidStatusReturns400(): void
    {
        $coordId = $this->createUser('coord4@example.com');

        $result = dispatch(
            'GET',
            '/coordination/queue?status=bad',
            [],
            ['user_id' => $coordId, '__user_roles' => ['coordination']],
        );

        $this->assertSame(400, $result['status']);
    }

    // =========================================================================
    // Invalid sort → 400
    // =========================================================================

    public function testInvalidSortReturns400(): void
    {
        $coordId = $this->createUser('coord5@example.com');

        $result = dispatch(
            'GET',
            '/coordination/queue?sort=random',
            [],
            ['user_id' => $coordId, '__user_roles' => ['coordination']],
        );

        $this->assertSame(400, $result['status']);
    }

    // =========================================================================
    // Invalid month → 400
    // =========================================================================

    public function testInvalidMonthReturns400(): void
    {
        $coordId = $this->createUser('coord6@example.com');

        $result = dispatch(
            'GET',
            '/coordination/queue?month=not-a-month',
            [],
            ['user_id' => $coordId, '__user_roles' => ['coordination']],
        );

        $this->assertSame(400, $result['status']);
    }

    // =========================================================================
    // Q3.4a: tab=announcements → 200 + queue heading (no placeholder)
    // =========================================================================

    public function testAnnouncementsTabReturns200WithQueueHeading(): void
    {
        $coordId = $this->createUser('coord7@example.com');

        $result = dispatch(
            'GET',
            '/coordination/queue?tab=announcements',
            [],
            ['user_id' => $coordId, '__user_roles' => ['coordination']],
        );

        $this->assertSame(200, $result['status']);
        $this->assertStringContainsString('Queue – Ankündigungen', $result['body']);
        $this->assertStringNotContainsString('Noch nicht implementiert.', $result['body']);
    }

    // =========================================================================
    // tab=announcements with data → announcement row visible
    // =========================================================================

    public function testAnnouncementsTabShowsAnnouncementRow(): void
    {
        $empId   = $this->createUser('emp_ann@example.com');
        $coordId = $this->createUser('coord9@example.com');

        $this->insertAnnouncement($empId, '2026-04-15', 'pending_approval');

        $result = dispatch(
            'GET',
            '/coordination/queue?tab=announcements&month=2026-04',
            [],
            ['user_id' => $coordId, '__user_roles' => ['coordination']],
        );

        $this->assertSame(200, $result['status']);
        $this->assertStringContainsString('emp_ann@example.com', $result['body']);
        $this->assertStringContainsString('2026-04-15', $result['body']);
    }

    // =========================================================================
    // tab=announcements with extra unknown param → 400 (whitelist still applies)
    // =========================================================================

    public function testAnnouncementsTabWithUnknownParamReturns400(): void
    {
        $coordId = $this->createUser('coord8@example.com');

        $result = dispatch(
            'GET',
            '/coordination/queue?tab=announcements&garbage=yes',
            [],
            ['user_id' => $coordId, '__user_roles' => ['coordination']],
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

    private function insertAnnouncement(int $userId, string $dateLocal, string $status): int
    {
        $this->pdo->prepare(
            "INSERT INTO announcements
                 (user_id, date_local, planned_start_at, planned_end_at,
                  break_minutes, net_minutes, reason, status, created_at, updated_at)
             VALUES
                 (:user_id, :date_local,
                  :date_local || ' 09:00:00', :date_local || ' 17:00:00',
                  30, 450, 'Planned shift', :status, '2026-01-01 00:00:00', '2026-01-01 00:00:00')"
        )->execute([':user_id' => $userId, ':date_local' => $dateLocal, ':status' => $status]);
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

        $pdo->exec("CREATE TABLE announcements (
            id               INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id          INTEGER NOT NULL,
            date_local       TEXT    NOT NULL,
            planned_start_at TEXT    NOT NULL,
            planned_end_at   TEXT    NOT NULL,
            break_minutes    INTEGER NOT NULL DEFAULT 0,
            net_minutes      INTEGER NOT NULL,
            reason           TEXT    NOT NULL,
            status           TEXT    NOT NULL DEFAULT 'pending_approval',
            created_at       TEXT    NOT NULL,
            updated_at       TEXT    NOT NULL
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
