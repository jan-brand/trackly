<?php

declare(strict_types=1);

namespace App\Domain\Announcement;

use PDO;

/**
 * Handles all write operations for announcements.
 *
 * Every write method:
 *   – runs inside exactly one DB transaction
 *   – appends exactly one row to announcement_audit_log
 *
 * Status rules:
 *   - create  ⇒ pending_approval
 *   - update  ⇒ if previously approved ⇒ pending_approval; otherwise unchanged
 */
final class AnnouncementService
{
    private const AUDIT_REASON_APPROVE = 'Freigegeben';
    private const AUDIT_REASON_REJECT  = 'Abgelehnt';

    public function __construct(private readonly PDO $pdo) {}

    /**
     * Create a new announcement for the given employee.
     *
     * @param  int                  $userId  Owner of the announcement
     * @param  array<string, mixed> $input   Validated + derived fields from AnnouncementValidator
     * @return int                           The new announcement ID
     */
    public function create(int $userId, array $input): int
    {
        $this->pdo->beginTransaction();

        try {
            $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

            $stmt = $this->pdo->prepare(
                'INSERT INTO announcements
                     (user_id, date_local, planned_start_at, planned_end_at,
                      break_minutes, net_minutes, reason, status, created_at, updated_at)
                 VALUES
                     (:user_id, :date_local, :planned_start_at, :planned_end_at,
                      :break_minutes, :net_minutes, :reason, :status, :created_at, :updated_at)'
            );

            $stmt->execute([
                ':user_id'          => $userId,
                ':date_local'       => $input['date_local'],
                ':planned_start_at' => $input['planned_start_at'],
                ':planned_end_at'   => $input['planned_end_at'],
                ':break_minutes'    => $input['break_minutes'] ?? 0,
                ':net_minutes'      => $input['net_minutes'],
                ':reason'           => $input['reason'],
                ':status'           => 'pending_approval',
                ':created_at'       => $now,
                ':updated_at'       => $now,
            ]);

            $announcementId = (int) $this->pdo->lastInsertId();
            $newRow = $this->fetchRow($announcementId);

            $this->insertAuditLog($announcementId, $userId, 'create', null, $newRow, $now);

            $this->pdo->commit();

            return $announcementId;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Update an existing announcement.
     *
     * If the announcement was previously approved it is reset to pending_approval.
     *
     * @param  int                  $actorUserId     ID of the user performing the action
     * @param  int                  $announcementId  ID of the announcement to update
     * @param  array<string, mixed> $input           Validated + derived fields from AnnouncementValidator
     */
    public function update(int $actorUserId, int $announcementId, array $input): void
    {
        $this->pdo->beginTransaction();

        try {
            $now    = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
            $oldRow = $this->fetchRow($announcementId);

            $prevStatus = (string) $oldRow['status'];
            $newStatus  = $prevStatus === 'approved' ? 'pending_approval' : $prevStatus;

            $this->pdo->prepare(
                'UPDATE announcements
                    SET date_local       = :date_local,
                        planned_start_at = :planned_start_at,
                        planned_end_at   = :planned_end_at,
                        break_minutes    = :break_minutes,
                        net_minutes      = :net_minutes,
                        reason           = :reason,
                        status           = :status,
                        updated_at       = :updated_at
                  WHERE id = :id'
            )->execute([
                ':date_local'       => $input['date_local'],
                ':planned_start_at' => $input['planned_start_at'],
                ':planned_end_at'   => $input['planned_end_at'],
                ':break_minutes'    => $input['break_minutes'] ?? 0,
                ':net_minutes'      => $input['net_minutes'],
                ':reason'           => $input['reason'],
                ':status'           => $newStatus,
                ':updated_at'       => $now,
                ':id'               => $announcementId,
            ]);

            $newRow = $this->fetchRow($announcementId);

            $this->insertAuditLog($announcementId, $actorUserId, 'update', $oldRow, $newRow, $now);

            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Approve an announcement.
     *
     * Sets status to 'approved' and writes one audit row (action=approve).
     *
     * @param int $actorUserId  ID of the coordination/admin user performing the action
     * @param int $announcementId
     */
    public function approve(int $actorUserId, int $announcementId): void
    {
        $this->pdo->beginTransaction();

        try {
            $now    = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
            $oldRow = $this->fetchRow($announcementId);

            $this->pdo->prepare(
                'UPDATE announcements SET status = :status, updated_at = :updated_at WHERE id = :id'
            )->execute([':status' => 'approved', ':updated_at' => $now, ':id' => $announcementId]);

            $newRow = $this->fetchRow($announcementId);

            $this->insertAuditLog($announcementId, $actorUserId, 'approve', $oldRow, $newRow, $now, self::AUDIT_REASON_APPROVE);

            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Reject an announcement.
     *
     * Sets status to 'rejected' and writes one audit row (action=reject).
     *
     * @param int $actorUserId  ID of the coordination/admin user performing the action
     * @param int $announcementId
     */
    public function reject(int $actorUserId, int $announcementId): void
    {
        $this->pdo->beginTransaction();

        try {
            $now    = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
            $oldRow = $this->fetchRow($announcementId);

            $this->pdo->prepare(
                'UPDATE announcements SET status = :status, updated_at = :updated_at WHERE id = :id'
            )->execute([':status' => 'rejected', ':updated_at' => $now, ':id' => $announcementId]);

            $newRow = $this->fetchRow($announcementId);

            $this->insertAuditLog($announcementId, $actorUserId, 'reject', $oldRow, $newRow, $now, self::AUDIT_REASON_REJECT);

            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function fetchRow(int $id): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM announcements WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            throw new \RuntimeException("Announcement {$id} not found.");
        }

        return $row;
    }

    /**
     * @param array<string, mixed>|null $oldRow
     * @param array<string, mixed>      $newRow
     */
    private function insertAuditLog(
        int $announcementId,
        int $actorUserId,
        string $action,
        ?array $oldRow,
        array $newRow,
        string $now,
        ?string $reason = null,
    ): void {
        $fields = ['user_id', 'date_local', 'planned_start_at', 'planned_end_at',
                   'break_minutes', 'net_minutes', 'reason', 'status'];

        $snapshot = static function (array $row) use ($fields): array {
            return array_intersect_key($row, array_flip($fields));
        };

        $this->pdo->prepare(
            'INSERT INTO announcement_audit_log
                 (announcement_id, actor_user_id, action, reason, old_json, new_json, created_at)
             VALUES
                 (:announcement_id, :actor_user_id, :action, :reason, :old_json, :new_json, :created_at)'
        )->execute([
            ':announcement_id' => $announcementId,
            ':actor_user_id'   => $actorUserId,
            ':action'          => $action,
            ':reason'          => $reason,
            ':old_json'        => $oldRow !== null ? json_encode($snapshot($oldRow), JSON_THROW_ON_ERROR) : null,
            ':new_json'        => json_encode($snapshot($newRow), JSON_THROW_ON_ERROR),
            ':created_at'      => $now,
        ]);
    }
}
