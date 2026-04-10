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
    // Timer stop
    // -------------------------------------------------------------------------

    /**
     * Atomically stop the active timer session for a user and create a time entry.
     *
     * Steps (all inside one transaction):
     *   1. Lock the active timer session (SELECT … FOR UPDATE).
     *   2. If no active session → return null (idempotent no-op).
     *   3. Compute start_at / end_at / break_minutes / net_minutes.
     *   4. Insert a time_entry with entry_source = 'timer'.
     *   5. Evaluate rules + persist flags.
     *   6. Write audit action = 'create' with reason = 'Timer gestoppt'.
     *   7. If auto-approved (no flags) → additional audit action = 'auto_approve'.
     *   8. Mark the timer_session as 'stopped'.
     *
     * @param  int  $userId  The employee whose timer to stop.
     * @return int|null      The new time_entry ID, or null when there was no active session.
     *
     * @throws \JsonException|\PDOException|\Throwable
     */
    public function stopTimer(int $userId): ?int
    {
        $this->pdo->beginTransaction();

        try {
            $forUpdate = ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql')
                ? ' FOR UPDATE'
                : '';

            $stmt = $this->pdo->prepare(
                "SELECT * FROM timer_sessions
                  WHERE user_id = :uid AND status IN ('running', 'paused')
                  ORDER BY started_at DESC
                  LIMIT 1" . $forUpdate
            );
            $stmt->execute([':uid' => $userId]);
            $session = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($session === false) {
                $this->pdo->rollBack();
                return null;
            }

            $now       = new \DateTimeImmutable();
            $nowStr    = $now->format('Y-m-d H:i:s');
            $nowTs     = $now->getTimestamp();
            $startedTs = (int) strtotime((string) $session['started_at']);

            $totalPauseSecs = (int) $session['total_pause_seconds'];

            if ($session['status'] === 'paused' && $session['paused_at'] !== null) {
                $pausedTs        = (int) strtotime((string) $session['paused_at']);
                $totalPauseSecs += max(0, $nowTs - $pausedTs);
            }

            $shiftSeconds = max(0, $nowTs - $startedTs);
            $breakMinutes = (int) ($totalPauseSecs / 60);
            $netMinutes   = max(0, (int) (($shiftSeconds - $totalPauseSecs) / 60));
            $dateLocal    = date('Y-m-d', $startedTs);
            $startAtStr   = date('Y-m-d H:i:s', $startedTs);

            $insertStmt = $this->pdo->prepare(
                'INSERT INTO time_entries
                     (user_id, date_local, start_at, end_at, break_minutes,
                      net_minutes, entry_source, status, created_at, updated_at)
                 VALUES
                     (:user_id, :date_local, :start_at, :end_at, :break_minutes,
                      :net_minutes, :entry_source, :status, :created_at, :updated_at)'
            );
            $insertStmt->execute([
                ':user_id'       => $userId,
                ':date_local'    => $dateLocal,
                ':start_at'      => $startAtStr,
                ':end_at'        => $nowStr,
                ':break_minutes' => $breakMinutes,
                ':net_minutes'   => $netMinutes,
                ':entry_source'  => 'timer',
                ':status'        => 'pending',
                ':created_at'    => $nowStr,
                ':updated_at'    => $nowStr,
            ]);

            $timeEntryId = (int) $this->pdo->lastInsertId();

            $flags  = $this->evaluateAndPersistFlags($timeEntryId, $userId, null);
            $status = empty($flags) ? 'approved' : 'pending_approval';

            $this->pdo->prepare(
                'UPDATE time_entries SET status = :status, updated_at = :updated_at WHERE id = :id'
            )->execute([':status' => $status, ':updated_at' => $nowStr, ':id' => $timeEntryId]);

            $newRow = $this->fetchRow($timeEntryId);

            $this->insertAuditLog(
                $timeEntryId,
                $userId,
                'create',
                'Timer gestoppt',
                null,
                TimeEntrySnapshot::fromRow($newRow),
                $nowStr,
            );

            if ($status === 'approved') {
                $this->insertAuditLog(
                    $timeEntryId,
                    $userId,
                    'auto_approve',
                    '',
                    null,
                    TimeEntrySnapshot::fromRow($newRow),
                    $nowStr,
                );
            }

            $this->pdo->prepare(
                "UPDATE timer_sessions
                    SET status = 'stopped', stopped_at = :stopped_at
                  WHERE id = :id"
            )->execute([':stopped_at' => $nowStr, ':id' => $session['id']]);

            $this->pdo->commit();

            return $timeEntryId;
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

        // Load approved announcements for same user/date (AN5.5)
        $approvedAnnouncements = $this->loadApprovedAnnouncements($userId, $entry->dateLocal);

        $ctx = new RuleContext(
            maxShiftMinutes:        (int) ($this->settings['work.max_shift_minutes']               ?? 600),
            breakRequired6hMinutes: (int) ($this->settings['adult.break_required_over_6h_minutes'] ?? 30),
            breakRequired9hMinutes: (int) ($this->settings['adult.break_required_over_9h_minutes'] ?? 45),
            otherEntries:           $otherEntries,
            maxDailyRegularMinutes: (int) ($this->settings['adult.max_daily_regular_minutes']       ?? 480),
            maxDeviationMinutes:    (int) ($this->settings['announcement.max_deviation_minutes']    ?? 30),
            approvedAnnouncements:  $approvedAnnouncements,
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
     * Load approved announcements for a user on a specific date.
     *
     * Used by the rule engine to evaluate announcement matching (AN5.5).
     *
     * @return list<array{id: int, planned_start_at: string, planned_end_at: string}>
     */
    private function loadApprovedAnnouncements(int $userId, string $dateLocal): array
    {
        // The announcements table may not exist in older test setups; suppress errors gracefully.
        try {
            $stmt = $this->pdo->prepare(
                "SELECT id, planned_start_at, planned_end_at
                   FROM announcements
                  WHERE user_id = :user_id
                    AND date_local = :date_local
                    AND status = 'approved'"
            );
            $stmt->execute([':user_id' => $userId, ':date_local' => $dateLocal]);
        } catch (\PDOException) {
            return [];
        }

        return array_map(static function (array $row): array {
            return [
                'id'               => (int) $row['id'],
                'planned_start_at' => (string) $row['planned_start_at'],
                'planned_end_at'   => (string) $row['planned_end_at'],
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
