<?php

declare(strict_types=1);

namespace App\Domain\Time;

use PDO;

/**
 * Handles all write operations for time entries.
 *
 * Every public method:
 *   – runs inside exactly one DB transaction
 *   – appends exactly one row to time_entry_audit_log
 *
 * Controllers must not write to time_entries directly; all writes go through
 * this service.
 */
final class TimeEntryService
{
    public function __construct(private readonly PDO $pdo) {}

    /**
     * Create a manually-entered time entry for a target user.
     *
     * @param  int                  $actorUserId   ID of the user performing the action
     * @param  int                  $targetUserId  ID of the employee the entry belongs to
     * @param  array<string, mixed> $input         Validated entry fields (+ optional 'reason')
     * @return int                                  The new time_entry ID
     *
     * @throws \JsonException|\PDOException|\Throwable
     */
    public function createManual(int $actorUserId, int $targetUserId, array $input): int
    {
        $this->pdo->beginTransaction();

        try {
            $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

            $stmt = $this->pdo->prepare(
                'INSERT INTO time_entries
                     (user_id, date_local, start_at, end_at, break_minutes,
                      net_minutes, entry_source, status, created_at, updated_at)
                 VALUES
                     (:user_id, :date_local, :start_at, :end_at, :break_minutes,
                      :net_minutes, :entry_source, :status, :created_at, :updated_at)'
            );

            $stmt->execute([
                ':user_id'       => $targetUserId,
                ':date_local'    => $input['date_local'],
                ':start_at'      => $input['start_at'],
                ':end_at'        => $input['end_at'],
                ':break_minutes' => $input['break_minutes'] ?? 0,
                ':net_minutes'   => $input['net_minutes'],
                ':entry_source'  => 'manual',
                ':status'        => $input['status'] ?? 'pending',
                ':created_at'    => $now,
                ':updated_at'    => $now,
            ]);

            $timeEntryId = (int) $this->pdo->lastInsertId();

            $newRow = $this->fetchRow($timeEntryId);

            $this->insertAuditLog(
                $timeEntryId,
                $actorUserId,
                'create',
                (string) ($input['reason'] ?? ''),
                null,
                TimeEntrySnapshot::fromRow($newRow),
                $now,
            );

            $this->pdo->commit();

            return $timeEntryId;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Update an existing time entry.
     *
     * @param  int                  $actorUserId   ID of the user performing the action
     * @param  int                  $timeEntryId   ID of the entry to update
     * @param  array<string, mixed> $input         Fields to update (+ optional 'reason')
     *
     * @throws \JsonException|\PDOException|\Throwable
     */
    public function update(int $actorUserId, int $timeEntryId, array $input): void
    {
        $this->pdo->beginTransaction();

        try {
            $now    = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
            $oldRow = $this->fetchRow($timeEntryId);

            $updatable = ['date_local', 'start_at', 'end_at', 'break_minutes', 'net_minutes', 'status'];
            $setClauses = [];
            $params     = [':id' => $timeEntryId, ':updated_at' => $now];

            foreach ($updatable as $field) {
                if (!array_key_exists($field, $input)) {
                    continue;
                }
                if (!preg_match('/^[a-z_]+$/', $field)) {
                    throw new \InvalidArgumentException("Invalid field name: {$field}");
                }
                $setClauses[] = "`{$field}` = :{$field}";
                $params[":{$field}"] = $input[$field];
            }

            if (!empty($setClauses)) {
                $setClauses[] = '`updated_at` = :updated_at';
                $sql = 'UPDATE time_entries SET ' . implode(', ', $setClauses) . ' WHERE id = :id';
                $this->pdo->prepare($sql)->execute($params);
            }

            $newRow = $this->fetchRow($timeEntryId);

            $this->insertAuditLog(
                $timeEntryId,
                $actorUserId,
                'update',
                (string) ($input['reason'] ?? ''),
                TimeEntrySnapshot::fromRow($oldRow),
                TimeEntrySnapshot::fromRow($newRow),
                $now,
            );

            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Cancel a time entry.
     *
     * @param  int    $actorUserId   ID of the user performing the action
     * @param  int    $timeEntryId   ID of the entry to cancel
     * @param  string $reason        Mandatory cancellation reason
     *
     * @throws \JsonException|\PDOException|\Throwable
     */
    public function cancel(int $actorUserId, int $timeEntryId, string $reason): void
    {
        $this->pdo->beginTransaction();

        try {
            $now    = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
            $oldRow = $this->fetchRow($timeEntryId);

            $this->pdo->prepare(
                'UPDATE time_entries
                 SET status = :status,
                     cancelled_by_user_id = :cancelled_by,
                     cancelled_at = :cancelled_at,
                     updated_at = :updated_at
                 WHERE id = :id'
            )->execute([
                ':status'       => 'cancelled',
                ':cancelled_by' => $actorUserId,
                ':cancelled_at' => $now,
                ':updated_at'   => $now,
                ':id'           => $timeEntryId,
            ]);

            $newRow = $this->fetchRow($timeEntryId);

            $this->insertAuditLog(
                $timeEntryId,
                $actorUserId,
                'cancel',
                $reason,
                TimeEntrySnapshot::fromRow($oldRow),
                TimeEntrySnapshot::fromRow($newRow),
                $now,
            );

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
     * @throws \RuntimeException when the entry is not found
     */
    private function fetchRow(int $timeEntryId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM time_entries WHERE id = :id');
        $stmt->execute([':id' => $timeEntryId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            throw new \RuntimeException("time_entries row {$timeEntryId} not found.");
        }

        return $row;
    }

    /**
     * @param  array<string, mixed>|null $oldSnapshot
     * @param  array<string, mixed>      $newSnapshot
     * @throws \JsonException
     */
    private function insertAuditLog(
        int $timeEntryId,
        int $actorUserId,
        string $action,
        string $reason,
        ?array $oldSnapshot,
        array $newSnapshot,
        string $now,
    ): void {
        $this->pdo->prepare(
            'INSERT INTO time_entry_audit_log
                 (time_entry_id, actor_user_id, action, reason, old_json, new_json, created_at)
             VALUES
                 (:time_entry_id, :actor_user_id, :action, :reason, :old_json, :new_json, :created_at)'
        )->execute([
            ':time_entry_id' => $timeEntryId,
            ':actor_user_id' => $actorUserId,
            ':action'        => $action,
            ':reason'        => $reason,
            ':old_json'      => $oldSnapshot !== null
                ? json_encode($oldSnapshot, JSON_THROW_ON_ERROR)
                : null,
            ':new_json'      => json_encode($newSnapshot, JSON_THROW_ON_ERROR),
            ':created_at'    => $now,
        ]);
    }
}
