<?php

declare(strict_types=1);

namespace App\Domain\Settings;

use PDO;

/**
 * Persists validated settings and appends an immutable audit-log entry,
 * all within a single database transaction.
 *
 * Call flow (atomic):
 *   1. Load old snapshot of all registry keys.
 *   2. REPLACE INTO settings for every submitted key.
 *   3. Load new snapshot from DB (confirms what was written).
 *   4. INSERT one row into settings_audit_log.
 *   5. COMMIT  (or ROLLBACK on any error).
 */
final class SettingsWriter
{
    public function __construct(
        private readonly PDO              $pdo,
        private readonly SettingsRegistry $registry,
    ) {}

    /**
     * Persist validated settings and record the change in the audit log.
     *
     * @param int                  $userId  ID of the acting admin user
     * @param string               $reason  Mandatory change justification
     * @param array<string, mixed> $values  Validated and normalised setting values
     *
     * @throws \JsonException|\PDOException|\Throwable
     */
    public function save(int $userId, string $reason, array $values): void
    {
        $this->pdo->beginTransaction();

        try {
            $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

            // ----------------------------------------------------------------
            // 1. Old snapshot (read inside transaction for isolation)
            // ----------------------------------------------------------------
            $oldSnapshot = $this->loadSnapshot();

            // ----------------------------------------------------------------
            // 2. Upsert all submitted, whitelisted keys
            //    REPLACE INTO is understood by both MariaDB and SQLite.
            // ----------------------------------------------------------------
            $upsert = $this->pdo->prepare(
                'REPLACE INTO settings (`key`, `value_json`, `updated_by_user_id`, `updated_at`)
                 VALUES (:key, :value_json, :user_id, :now)'
            );

            foreach ($values as $key => $value) {
                if (!$this->registry->has($key)) {
                    continue;
                }
                $upsert->execute([
                    ':key'        => $key,
                    ':value_json' => json_encode($value, JSON_THROW_ON_ERROR),
                    ':user_id'    => $userId,
                    ':now'        => $now,
                ]);
            }

            // ----------------------------------------------------------------
            // 3. New snapshot (re-read from DB to confirm written state)
            // ----------------------------------------------------------------
            $newSnapshot = $this->loadSnapshot();

            // ----------------------------------------------------------------
            // 4. Append one audit row
            // ----------------------------------------------------------------
            $this->pdo->prepare(
                'INSERT INTO settings_audit_log
                     (`actor_user_id`, `action`, `reason`, `old_value_json`, `new_value_json`, `created_at`)
                 VALUES (:user_id, :action, :reason, :old, :new, :now)'
            )->execute([
                ':user_id' => $userId,
                ':action'  => 'update',
                ':reason'  => $reason,
                ':old'     => json_encode($oldSnapshot, JSON_THROW_ON_ERROR),
                ':new'     => json_encode($newSnapshot, JSON_THROW_ON_ERROR),
                ':now'     => $now,
            ]);

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
     * Load all registry keys from the DB, falling back to registry defaults
     * for any key that is not yet persisted.
     *
     * @return array<string, mixed>
     */
    private function loadSnapshot(): array
    {
        $snapshot = [];
        foreach ($this->registry->all() as $def) {
            $snapshot[$def->key] = $def->default;
        }

        $stmt = $this->pdo->query('SELECT `key`, `value_json` FROM settings');
        if ($stmt !== false) {
            foreach ($stmt->fetchAll() as $row) {
                $key = $row['key'];
                if ($this->registry->has($key)) {
                    $snapshot[$key] = json_decode(
                        (string) $row['value_json'],
                        true,
                        512,
                        JSON_THROW_ON_ERROR,
                    );
                }
            }
        }

        return $snapshot;
    }
}
