<?php

declare(strict_types=1);

/**
 * Seed S1.6 – Insert default values for every registered setting key.
 *
 * Rules:
 *  - If a key is NOT yet present in the `settings` table → INSERT.
 *  - If a key already exists                             → do nothing.
 *  - `updated_by_user_id` is set to the first admin user in the DB.
 *
 * The seed is idempotent: running it multiple times never changes an
 * already-persisted row and never adds duplicate rows.
 */

use App\Domain\Settings\SettingsRegistry;

return function (PDO $pdo): void {
    $registry = new SettingsRegistry();

    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

    $hasColumn = static function (string $column) use ($pdo, $driver): bool {
        if ($driver === 'sqlite') {
            $cols = $pdo->query("PRAGMA table_info(settings)");
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

        $stmt = $pdo->prepare("SHOW COLUMNS FROM settings LIKE :column");
        if ($stmt === false) {
            return false;
        }

        $stmt->execute([':column' => $column]);
        return $stmt->fetch(PDO::FETCH_ASSOC) !== false;
    };

    $hasLabel = $hasColumn('label');
    $hasUiType = $hasColumn('ui_type');

    // Resolve the admin user id (first user with the 'admin' role).
    $adminUserId = 0;
    $stmt = $pdo->query(
        "SELECT ur.user_id
           FROM user_roles ur
           JOIN roles r ON r.id = ur.role_id
          WHERE r.`key` = 'admin'
          ORDER BY ur.user_id ASC
          LIMIT 1"
    );
    if ($stmt !== false) {
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row !== false) {
            $adminUserId = (int) $row['user_id'];
        }
    }

    $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

    // Build insert dynamically so seeding also works before migration M0005.
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

    if ($driver === 'sqlite') {
        $sql = 'INSERT OR IGNORE INTO settings (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $values) . ')';
    } else {
        $sql = 'INSERT IGNORE INTO settings (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $values) . ')';
    }

    $insert = $pdo->prepare($sql);

    $update = null;
    $updateSqlParts = [];
    $updateWhereParts = ['`key` = :key'];

    if ($hasLabel) {
        $updateSqlParts[] = '`label` = :label';
        $updateWhereParts[] = "`label` = ''";
    }
    if ($hasUiType) {
        $updateSqlParts[] = '`ui_type` = :ui_type';
        if (!$hasLabel) {
            $updateWhereParts[] = "`ui_type` = ''";
        }
    }

    if ($updateSqlParts !== []) {
        $update = $pdo->prepare(
            'UPDATE settings SET ' . implode(', ', $updateSqlParts)
            . ' WHERE ' . implode(' AND ', $updateWhereParts)
        );
    }

    foreach ($registry->all() as $def) {
        $insertParams = [
            ':key'        => $def->key,
            ':value_json' => json_encode($def->default, JSON_THROW_ON_ERROR),
            ':user_id'    => $adminUserId,
            ':now'        => $now,
        ];

        if ($hasLabel) {
            $insertParams[':label'] = $def->label ?? '';
        }
        if ($hasUiType) {
            $insertParams[':ui_type'] = $def->uiType ?? '';
        }

        $insert->execute($insertParams);

        if ($update !== null) {
            $params = [':key' => $def->key];
            if ($hasLabel) {
                $params[':label'] = $def->label ?? '';
            }
            if ($hasUiType) {
                $params[':ui_type'] = $def->uiType ?? '';
            }

            $update->execute($params);
        }
    }
};
