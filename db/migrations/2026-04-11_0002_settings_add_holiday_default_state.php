<?php

declare(strict_types=1);

/**
 * Backfill migration for new setting key `holiday.default_state`.
 *
 * Ensures existing installations get a persisted default row so the setting
 * is present in the DB even before the next explicit admin save.
 *
 * Idempotent:
 * - SQLite: INSERT OR IGNORE
 * - MariaDB/MySQL: INSERT IGNORE
 */
return function (PDO $pdo): void {
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

    $hasColumn = static function (string $column) use ($pdo, $driver): bool {
        if ($driver === 'sqlite') {
            $cols = $pdo->query('PRAGMA table_info(settings)');
            if ($cols === false) {
                return false;
            }

            foreach ($cols->fetchAll(PDO::FETCH_ASSOC) as $col) {
                if (($col['name'] ?? null) === $column) {
                    return true;
                }
            }

            return false;
        }

        $stmt = $pdo->prepare('SHOW COLUMNS FROM settings LIKE :column');
        if ($stmt === false) {
            return false;
        }

        $stmt->execute([':column' => $column]);
        return $stmt->fetch(PDO::FETCH_ASSOC) !== false;
    };

    // settings.updated_by_user_id is required; resolve a valid user id.
    $userId = null;

    $stmt = $pdo->query('SELECT updated_by_user_id FROM settings ORDER BY updated_at DESC LIMIT 1');
    if ($stmt !== false) {
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row !== false) {
            $userId = (int) $row['updated_by_user_id'];
        }
    }

    if ($userId === null || $userId <= 0) {
        $stmt = $pdo->query('SELECT id FROM users ORDER BY id ASC LIMIT 1');
        if ($stmt !== false) {
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row !== false) {
                $userId = (int) $row['id'];
            }
        }
    }

    // If there is no user yet, skip safely; the setting will still be inserted
    // by SettingsWriter on the next admin save.
    if ($userId === null || $userId <= 0) {
        return;
    }

    $hasLabel = $hasColumn('label');
    $hasUiType = $hasColumn('ui_type');

    $columns = ['`key`', '`value_json`'];
    $values = [':key', ':value_json'];

    if ($hasLabel) {
        $columns[] = '`label`';
        $values[] = ':label';
    }
    if ($hasUiType) {
        $columns[] = '`ui_type`';
        $values[] = ':ui_type';
    }

    $columns[] = '`updated_by_user_id`';
    $columns[] = '`updated_at`';
    $values[] = ':user_id';
    $values[] = ':now';

    $sql = ($driver === 'sqlite' ? 'INSERT OR IGNORE INTO settings ' : 'INSERT IGNORE INTO settings ')
        . '(' . implode(', ', $columns) . ') VALUES (' . implode(', ', $values) . ')';

    $stmt = $pdo->prepare($sql);
    $params = [
        ':key' => 'holiday.default_state',
        ':value_json' => json_encode('BE', JSON_THROW_ON_ERROR),
        ':user_id' => $userId,
        ':now' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
    ];

    if ($hasLabel) {
        $params[':label'] = 'Standard-Bundesland für Feiertage-Import';
    }
    if ($hasUiType) {
        $params[':ui_type'] = '';
    }

    $stmt->execute($params);

    // Normalize legacy default value from BY -> BE, but only when the row still
    // contains the old default. Custom admin choices remain untouched.
    $select = $pdo->prepare('SELECT `value_json` FROM settings WHERE `key` = :key');
    $select->execute([':key' => 'holiday.default_state']);
    $currentJson = $select->fetchColumn();

    if ($currentJson !== false) {
        $decoded = json_decode((string) $currentJson, true);
        if ($decoded === 'BY') {
            $update = $pdo->prepare(
                'UPDATE settings
                    SET `value_json` = :value_json,
                        `updated_by_user_id` = :user_id,
                        `updated_at` = :now
                  WHERE `key` = :key'
            );
            $update->execute([
                ':value_json' => json_encode('BE', JSON_THROW_ON_ERROR),
                ':user_id' => $userId,
                ':now' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
                ':key' => 'holiday.default_state',
            ]);
        }
    }
};
