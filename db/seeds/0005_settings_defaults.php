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

    // Use INSERT OR IGNORE (SQLite) / INSERT IGNORE (MariaDB) depending on
    // the driver.  We detect via the driver name so both test (SQLite) and
    // production (MariaDB) work without changes.
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

    if ($driver === 'sqlite') {
        $sql = 'INSERT OR IGNORE INTO settings (`key`, `value_json`, `label`, `ui_type`, `updated_by_user_id`, `updated_at`)
                VALUES (:key, :value_json, :label, :ui_type, :user_id, :now)';
    } else {
        $sql = 'INSERT IGNORE INTO settings (`key`, `value_json`, `label`, `ui_type`, `updated_by_user_id`, `updated_at`)
                VALUES (:key, :value_json, :label, :ui_type, :user_id, :now)';
    }

    $insert = $pdo->prepare($sql);

    // Back-fill label/ui_type for rows that were inserted before this migration
    // (e.g. existing deployments).  Only updates rows where label is still empty.
    $update = $pdo->prepare(
        "UPDATE settings SET `label` = :label, `ui_type` = :ui_type
          WHERE `key` = :key AND `label` = ''"
    );

    foreach ($registry->all() as $def) {
        $insert->execute([
            ':key'        => $def->key,
            ':value_json' => json_encode($def->default, JSON_THROW_ON_ERROR),
            ':label'      => $def->label ?? '',
            ':ui_type'    => $def->uiType ?? '',
            ':user_id'    => $adminUserId,
            ':now'        => $now,
        ]);

        $update->execute([
            ':key'     => $def->key,
            ':label'   => $def->label ?? '',
            ':ui_type' => $def->uiType ?? '',
        ]);
    }
};
