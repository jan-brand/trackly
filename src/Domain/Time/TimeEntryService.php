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
 *   – re-evaluates rules and updates time_entry_flags + status (when RuleEngine is provided)
 *
 * Controllers must not write to time_entries directly; all writes go through
 * this service.
 */
final class TimeEntryService
{
    /**
     * @param PDO                    $pdo         Database connection
     * @param RuleEngineInterface|null $ruleEngine  When null, flag evaluation is skipped
     * @param array<string,mixed>    $settings    Application settings used to build RuleContext
     */
    public function __construct(
        private readonly PDO                  $pdo,
        private readonly ?RuleEngineInterface $ruleEngine = null,
        private readonly array                $settings   = [],
    ) {}

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
                ':status'        => 'pending',
                ':created_at'    => $now,
                ':updated_at'    => $now,
            ]);

            $timeEntryId = (int) $this->pdo->lastInsertId();

            // Evaluate rules + persist flags + set status
            $flags = $this->evaluateAndPersistFlags($timeEntryId, $targetUserId, null);
            $status = empty($flags) ? 'approved' : 'pending_approval';

            $this->pdo->prepare(
                'UPDATE time_entries SET status = :status, updated_at = :updated_at WHERE id = :id'
            )->execute([':status' => $status, ':updated_at' => $now, ':id' => $timeEntryId]);

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

            $updatable  = ['date_local', 'start_at', 'end_at', 'break_minutes', 'net_minutes', 'status'];
            $setClauses = [];
            $params     = [':id' => $timeEntryId, ':updated_at' => $now];

            foreach ($updatable as $field) {
                // $field comes exclusively from the $updatable whitelist above —
                // no user input reaches this interpolation.
                if (!array_key_exists($field, $input)) {
                    continue;
                }
                $setClauses[] = "`{$field}` = :{$field}";
                $params[":{$field}"] = $input[$field];
            }

            if (!empty($setClauses)) {
                $setClauses[] = '`updated_at` = :updated_at';
                $sql = 'UPDATE time_entries SET ' . implode(', ', $setClauses) . ' WHERE id = :id';
                $this->pdo->prepare($sql)->execute($params);
            }

            // Evaluate rules + persist flags + set status
            $userId = (int) $oldRow['user_id'];
            $flags  = $this->evaluateAndPersistFlags($timeEntryId, $userId, $timeEntryId);

            // Update of a previously approved entry → always pending_approval
            $prevStatus = (string) $oldRow['status'];
            if ($prevStatus === 'approved') {
                $newStatus = 'pending_approval';
            } else {
                $newStatus = empty($flags) ? 'approved' : 'pending_approval';
            }

            $this->pdo->prepare(
                'UPDATE time_entries SET status = :status, updated_at = :updated_at WHERE id = :id'
            )->execute([':status' => $newStatus, ':updated_at' => $now, ':id' => $timeEntryId]);

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
     * Run the rule engine (if configured), persist flags, and return the flag list.
     *
     * @param  int      $timeEntryId     The entry being evaluated
     * @param  int      $userId          The entry's owner
     * @param  int|null $excludeEntryId  Entry ID to exclude from overlap check (for updates)
     * @return list<Flag>
     * @throws \JsonException
     */
    private function evaluateAndPersistFlags(
        int $timeEntryId,
        int $userId,
        ?int $excludeEntryId,
    ): array {
        // Delete previous flags for this entry (delete + re-insert strategy)
        $this->pdo->prepare(
            'DELETE FROM time_entry_flags WHERE time_entry_id = :id'
        )->execute([':id' => $timeEntryId]);

        if ($this->ruleEngine === null) {
            return [];
        }

        $entryRow = $this->fetchRow($timeEntryId);
        $entry    = TimeEntry::fromRow($entryRow);

        // Build context: load other non-cancelled entries for this user
        $otherEntries = $this->loadOtherEntries($userId, $excludeEntryId);

        $ctx = new RuleContext(
            maxShiftMinutes:        (int) ($this->settings['work.max_shift_minutes']             ?? 600),
            breakRequired6hMinutes: (int) ($this->settings['adult.break_required_over_6h_minutes'] ?? 30),
            breakRequired9hMinutes: (int) ($this->settings['adult.break_required_over_9h_minutes'] ?? 45),
            otherEntries:           $otherEntries,
        );

        $flags = $this->ruleEngine->evaluate($entry, $ctx);

        // Persist flags
        if (!empty($flags)) {
            $insertFlag = $this->pdo->prepare(
                'INSERT INTO time_entry_flags (time_entry_id, flag_key, flag_value, sort_index, created_at)
                 VALUES (:time_entry_id, :flag_key, :flag_value, :sort_index, :created_at)'
            );
            $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
            foreach ($flags as $sortIndex => $flag) {
                $insertFlag->execute([
                    ':time_entry_id' => $timeEntryId,
                    ':flag_key'      => $flag->flagKey,
                    ':flag_value'    => $flag->flagValue,
                    ':sort_index'    => $sortIndex + 1,
                    ':created_at'    => $now,
                ]);
            }
        }

        return $flags;
    }

    /**
     * Load non-cancelled entries for a user, optionally excluding one entry.
     *
     * @return list<array{id: int, start_at: string, end_at: string}>
     */
    private function loadOtherEntries(int $userId, ?int $excludeId): array
    {
        if ($excludeId !== null) {
            $stmt = $this->pdo->prepare(
                "SELECT id, start_at, end_at FROM time_entries
                  WHERE user_id = :user_id AND status != 'cancelled' AND id != :exclude_id"
            );
            $stmt->execute([':user_id' => $userId, ':exclude_id' => $excludeId]);
        } else {
            $stmt = $this->pdo->prepare(
                "SELECT id, start_at, end_at FROM time_entries
                  WHERE user_id = :user_id AND status != 'cancelled'"
            );
            $stmt->execute([':user_id' => $userId]);
        }

        return array_map(static function (array $row): array {
            return [
                'id'       => (int) $row['id'],
                'start_at' => (string) $row['start_at'],
                'end_at'   => (string) $row['end_at'],
            ];
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

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
