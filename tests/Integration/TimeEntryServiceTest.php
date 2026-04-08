<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Domain\Time\TimeEntryService;
use App\Domain\Time\TimeEntrySnapshot;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for TimeEntryService.
 *
 * Uses an in-memory SQLite database so no real MariaDB connection is required.
 * Each test verifies that the corresponding service method writes exactly one
 * row into time_entry_audit_log.
 */
class TimeEntryServiceTest extends TestCase
{
    private PDO $pdo;
    private TimeEntryService $service;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $this->createSchema($this->pdo);

        $this->service = new TimeEntryService($this->pdo);
    }

    // -------------------------------------------------------------------------
    // createManual writes exactly 1 audit row
    // -------------------------------------------------------------------------

    public function testCreateManualWritesOneAuditRow(): void
    {
        $timeEntryId = $this->service->createManual(
            actorUserId:  1,
            targetUserId: 2,
            input: [
                'date_local'    => '2026-04-08',
                'start_at'      => '2026-04-08 09:00:00',
                'end_at'        => '2026-04-08 17:00:00',
                'break_minutes' => 30,
                'net_minutes'   => 450,
                'reason'        => 'Manually entered by coordinator',
            ],
        );

        $this->assertGreaterThan(0, $timeEntryId);
        $this->assertAuditRowCount($timeEntryId, 1);

        $row = $this->fetchAuditRows($timeEntryId)[0];
        $this->assertSame('create', $row['action']);
        $this->assertSame('Manually entered by coordinator', $row['reason']);
        $this->assertNull($row['old_json']);
        $this->assertNotNull($row['new_json']);
    }

    // -------------------------------------------------------------------------
    // update writes exactly 1 audit row
    // -------------------------------------------------------------------------

    public function testUpdateWritesOneAuditRow(): void
    {
        $timeEntryId = $this->service->createManual(
            actorUserId:  1,
            targetUserId: 2,
            input: [
                'date_local'    => '2026-04-08',
                'start_at'      => '2026-04-08 09:00:00',
                'end_at'        => '2026-04-08 17:00:00',
                'break_minutes' => 30,
                'net_minutes'   => 450,
            ],
        );

        $this->service->update(
            actorUserId:  1,
            timeEntryId:  $timeEntryId,
            input: [
                'break_minutes' => 45,
                'net_minutes'   => 435,
                'reason'        => 'Break duration corrected',
            ],
        );

        $this->assertAuditRowCount($timeEntryId, 2);

        $rows = $this->fetchAuditRows($timeEntryId);
        $updateRow = $rows[1];
        $this->assertSame('update', $updateRow['action']);
        $this->assertSame('Break duration corrected', $updateRow['reason']);
        $this->assertNotNull($updateRow['old_json']);
        $this->assertNotNull($updateRow['new_json']);
    }

    // -------------------------------------------------------------------------
    // cancel writes exactly 1 audit row
    // -------------------------------------------------------------------------

    public function testCancelWritesOneAuditRow(): void
    {
        $timeEntryId = $this->service->createManual(
            actorUserId:  1,
            targetUserId: 2,
            input: [
                'date_local'    => '2026-04-08',
                'start_at'      => '2026-04-08 09:00:00',
                'end_at'        => '2026-04-08 17:00:00',
                'break_minutes' => 0,
                'net_minutes'   => 480,
            ],
        );

        $this->service->cancel(
            actorUserId: 1,
            timeEntryId: $timeEntryId,
            reason:      'Entry created in error',
        );

        $this->assertAuditRowCount($timeEntryId, 2);

        $rows = $this->fetchAuditRows($timeEntryId);
        $cancelRow = $rows[1];
        $this->assertSame('cancel', $cancelRow['action']);
        $this->assertSame('Entry created in error', $cancelRow['reason']);

        $newSnapshot = json_decode((string) $cancelRow['new_json'], true);
        $this->assertSame('cancelled', $newSnapshot['status']);
    }

    // -------------------------------------------------------------------------
    // TimeEntrySnapshot::fromRow returns expected fields
    // -------------------------------------------------------------------------

    public function testSnapshotFromRowContainsExpectedFields(): void
    {
        $row = [
            'id'                   => 1,
            'user_id'              => 2,
            'date_local'           => '2026-04-08',
            'start_at'             => '2026-04-08 09:00:00',
            'end_at'               => '2026-04-08 17:00:00',
            'break_minutes'        => 30,
            'net_minutes'          => 450,
            'entry_source'         => 'manual',
            'status'               => 'pending',
            'approved_by_user_id'  => null,
            'approved_at'          => null,
            'cancelled_by_user_id' => null,
            'cancelled_at'         => null,
            'created_at'           => '2026-04-08 09:00:00',
            'updated_at'           => '2026-04-08 09:00:00',
        ];

        $snapshot = TimeEntrySnapshot::fromRow($row);

        $expectedKeys = [
            'user_id', 'date_local', 'start_at', 'end_at',
            'break_minutes', 'net_minutes', 'entry_source', 'status',
            'approved_by_user_id', 'approved_at', 'cancelled_by_user_id', 'cancelled_at',
        ];

        $this->assertSame($expectedKeys, array_keys($snapshot));
        $this->assertArrayNotHasKey('id', $snapshot);
        $this->assertArrayNotHasKey('created_at', $snapshot);
        $this->assertArrayNotHasKey('updated_at', $snapshot);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function assertAuditRowCount(int $timeEntryId, int $expected): void
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM time_entry_audit_log WHERE time_entry_id = :id'
        );
        $stmt->execute([':id' => $timeEntryId]);
        $this->assertSame($expected, (int) $stmt->fetchColumn());
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchAuditRows(int $timeEntryId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM time_entry_audit_log WHERE time_entry_id = :id ORDER BY id ASC'
        );
        $stmt->execute([':id' => $timeEntryId]);
        return $stmt->fetchAll();
    }

    private function createSchema(PDO $pdo): void
    {
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
    }
}
