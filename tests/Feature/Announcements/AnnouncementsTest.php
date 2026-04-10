<?php

declare(strict_types=1);

namespace App\Tests\Feature\Announcements;

use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * AN5.2 / AN5.2a – Announcements must-have tests.
 *
 * Tests:
 *   1. Employee cannot load another employee's announcement ⇒ 403.
 *   2. Overnight shift blocked when allow_overnight_shifts=false ⇒ 422.
 *
 * Uses an in-memory SQLite database injected via reflection into Db::instance.
 * All requests go through the full application router (simulateRequest / dispatch).
 */
class AnnouncementsTest extends TestCase
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
    // AN5.2 must-have: employee cannot load foreign announcement ⇒ 403
    // =========================================================================

    public function testEmployeeCannotLoadForeignAnnouncementReturns403(): void
    {
        $userA = $this->createUser('userA@example.com');
        $userB = $this->createUser('userB@example.com');

        // Create an announcement that belongs to user B
        $announcementId = $this->insertAnnouncement($userB);

        // User A tries to load user B's announcement ⇒ 403
        $result = simulateRequest(
            'GET',
            '/announcements/' . $announcementId,
            [],
            [],
            [
                'user_id'      => $userA,
                '__user_roles' => ['employee'],
            ],
        );

        $this->assertSame(403, $result['status']);
    }

    // =========================================================================
    // AN5.2a must-have: overnight blocked when allow_overnight_shifts=false ⇒ 422
    // =========================================================================

    public function testOvernightShiftBlockedWhenNotAllowedReturns422(): void
    {
        $userId = $this->createUser('emp@example.com');
        $token  = bin2hex(random_bytes(16));

        // allow_overnight_shifts is false by default (no settings row inserted)

        $result = simulateRequest(
            'POST',
            '/announcements',
            [
                'csrf_token'         => $token,
                'date'               => '2026-04-10',
                'planned_start_time' => '22:00',
                'planned_end_time'   => '06:00',  // end < start ⇒ overnight
                'break_minutes'      => '30',
                'reason'             => 'Night shift planned',
            ],
            [],
            [
                'user_id'        => $userId,
                '__user_roles'   => ['employee'],
                '__csrf_token'   => $token,
            ],
        );

        $this->assertSame(422, $result['status']);
        $this->assertStringContainsString('overnight', strtolower($result['body']));
    }

    // =========================================================================
    // Additional: valid create ⇒ 303 + status is pending_approval
    // =========================================================================

    public function testCreateValidAnnouncementReturns303AndPendingApproval(): void
    {
        $userId = $this->createUser('emp2@example.com');
        $token  = bin2hex(random_bytes(16));

        $result = simulateRequest(
            'POST',
            '/announcements',
            [
                'csrf_token'         => $token,
                'date'               => '2026-04-10',
                'planned_start_time' => '09:00',
                'planned_end_time'   => '17:00',
                'break_minutes'      => '30',
                'reason'             => 'Regular shift',
            ],
            [],
            [
                'user_id'        => $userId,
                '__user_roles'   => ['employee'],
                '__csrf_token'   => $token,
            ],
        );

        $this->assertSame(303, $result['status']);

        // Verify status is pending_approval
        $stmt = $this->pdo->prepare('SELECT status FROM announcements WHERE user_id = :uid');
        $stmt->execute([':uid' => $userId]);
        $this->assertSame('pending_approval', $stmt->fetchColumn());
    }

    // =========================================================================
    // Additional: update previously approved ⇒ back to pending_approval
    // =========================================================================

    public function testUpdateApprovedAnnouncementResetsToPendingApproval(): void
    {
        $userId = $this->createUser('emp3@example.com');
        $token  = bin2hex(random_bytes(16));

        $annId = $this->insertAnnouncement($userId, 'approved');

        $result = simulateRequest(
            'POST',
            '/announcements/' . $annId,
            [
                'csrf_token'         => $token,
                'date'               => '2026-04-11',
                'planned_start_time' => '08:00',
                'planned_end_time'   => '16:00',
                'break_minutes'      => '30',
                'reason'             => 'Updated shift plan',
            ],
            [],
            [
                'user_id'        => $userId,
                '__user_roles'   => ['employee'],
                '__csrf_token'   => $token,
            ],
        );

        $this->assertSame(303, $result['status']);

        $stmt = $this->pdo->prepare('SELECT status FROM announcements WHERE id = :id');
        $stmt->execute([':id' => $annId]);
        $this->assertSame('pending_approval', $stmt->fetchColumn());
    }

    // =========================================================================
    // Additional: missing reason ⇒ 422
    // =========================================================================

    public function testCreateWithMissingReasonReturns422(): void
    {
        $userId = $this->createUser('emp4@example.com');
        $token  = bin2hex(random_bytes(16));

        $result = simulateRequest(
            'POST',
            '/announcements',
            [
                'csrf_token'         => $token,
                'date'               => '2026-04-10',
                'planned_start_time' => '09:00',
                'planned_end_time'   => '17:00',
                'break_minutes'      => '30',
                'reason'             => 'ab',  // too short
            ],
            [],
            [
                'user_id'        => $userId,
                '__user_roles'   => ['employee'],
                '__csrf_token'   => $token,
            ],
        );

        $this->assertSame(422, $result['status']);
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

    private function insertAnnouncement(int $userId, string $status = 'pending_approval'): int
    {
        $this->pdo->prepare(
            "INSERT INTO announcements
                 (user_id, date_local, planned_start_at, planned_end_at, break_minutes, net_minutes,
                  reason, status, created_at, updated_at)
             VALUES
                 (:user_id, '2026-04-10', '2026-04-10 09:00:00', '2026-04-10 17:00:00',
                  30, 450, 'Planned shift', :status, '2026-04-10 08:00:00', '2026-04-10 08:00:00')"
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
                `key` TEXT    NOT NULL UNIQUE,
                name  TEXT    NOT NULL
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
                `key`                TEXT    NOT NULL PRIMARY KEY,
                `value_json`         TEXT    NOT NULL,
                `updated_by_user_id` INTEGER NULL,
                `updated_at`         TEXT    NOT NULL
            )
        ");

        $pdo->exec("
            CREATE TABLE announcements (
                id                INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id           INTEGER NOT NULL,
                date_local        TEXT    NOT NULL,
                planned_start_at  TEXT    NOT NULL,
                planned_end_at    TEXT    NOT NULL,
                break_minutes     INTEGER NOT NULL DEFAULT 0,
                net_minutes       INTEGER NOT NULL,
                reason            TEXT    NOT NULL,
                status            TEXT    NOT NULL DEFAULT 'pending_approval',
                created_at        TEXT    NOT NULL,
                updated_at        TEXT    NOT NULL
            )
        ");

        $pdo->exec("
            CREATE TABLE announcement_audit_log (
                id               INTEGER PRIMARY KEY AUTOINCREMENT,
                announcement_id  INTEGER NOT NULL,
                actor_user_id    INTEGER NOT NULL,
                action           TEXT    NOT NULL,
                old_json         TEXT    NULL,
                new_json         TEXT    NOT NULL,
                created_at       TEXT    NOT NULL
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
