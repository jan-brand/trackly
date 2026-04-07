<?php

declare(strict_types=1);

/**
 * Migration M0005 – add `label` and `ui_type` columns to the settings table.
 *
 * These columns are populated by the settings-defaults seed and allow the
 * UI to display human-readable labels and choose the correct input widget
 * from data stored in the database rather than from PHP source code.
 *
 * Idempotent: both SQLite (PRAGMA table_info check) and MariaDB
 * (ADD COLUMN IF NOT EXISTS) skip the ALTER when columns already exist.
 */
return function (PDO $pdo): void {
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

    if ($driver === 'sqlite') {
        $cols     = $pdo->query("PRAGMA table_info(settings)")->fetchAll(PDO::FETCH_ASSOC);
        $colNames = array_column($cols, 'name');

        if (!in_array('label', $colNames, true)) {
            $pdo->exec("ALTER TABLE settings ADD COLUMN label TEXT NOT NULL DEFAULT ''");
        }
        if (!in_array('ui_type', $colNames, true)) {
            $pdo->exec("ALTER TABLE settings ADD COLUMN ui_type TEXT NOT NULL DEFAULT ''");
        }
    } else {
        $pdo->exec(
            "ALTER TABLE settings
             ADD COLUMN IF NOT EXISTS `label`   VARCHAR(255) NOT NULL DEFAULT '',
             ADD COLUMN IF NOT EXISTS `ui_type` VARCHAR(20)  NOT NULL DEFAULT ''"
        );
    }
};
