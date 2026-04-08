<?php

declare(strict_types=1);

/**
 * Migration M2.4 – Add sort_index to time_entry_flags.
 *
 * sort_index stores the 1-based position of the flag as returned by
 * RuleEngine::evaluate(), enabling the UI to reproduce the stable
 * evaluation order exactly.
 */
return function (PDO $pdo): void {
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

    if ($driver === 'sqlite') {
        // SQLite: check if column already exists before adding
        $cols = $pdo->query("PRAGMA table_info(time_entry_flags)");
        if ($cols !== false) {
            $exists = false;
            foreach ($cols->fetchAll(PDO::FETCH_ASSOC) as $col) {
                if (($col['name'] ?? '') === 'sort_index') {
                    $exists = true;
                    break;
                }
            }
            if (!$exists) {
                $pdo->exec(
                    'ALTER TABLE time_entry_flags ADD COLUMN sort_index INTEGER NOT NULL DEFAULT 0'
                );
            }
        }
        return;
    }

    // MySQL / MariaDB: idempotent via information_schema check
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME   = 'time_entry_flags'
            AND COLUMN_NAME  = 'sort_index'"
    );
    $stmt->execute();
    $exists = (int) $stmt->fetchColumn() > 0;

    if (!$exists) {
        $pdo->exec(
            'ALTER TABLE time_entry_flags
             ADD COLUMN `sort_index` INT NOT NULL DEFAULT 0
             AFTER `flag_value`'
        );
    }
};
