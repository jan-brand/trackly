<?php

declare(strict_types=1);

/**
 * Migration 0003 – add `reason` column to announcement_audit_log.
 *
 * Idempotent for both SQLite (test) and MySQL/MariaDB (production).
 * Uses the information_schema check for MySQL so that the plain
 * `ALTER TABLE … ADD COLUMN` (without the MariaDB-only `IF NOT EXISTS`
 * extension) is only executed when the column is actually missing.
 */
return function (PDO $pdo): void {
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

    if ($driver === 'sqlite') {
        // SQLite does not support ADD COLUMN IF NOT EXISTS – guard manually.
        $cols = $pdo->query('PRAGMA table_info(announcement_audit_log)');
        if ($cols !== false) {
            $exists = false;
            foreach ($cols->fetchAll(PDO::FETCH_ASSOC) as $col) {
                if (($col['name'] ?? '') === 'reason') {
                    $exists = true;
                    break;
                }
            }
            if (!$exists) {
                $pdo->exec(
                    'ALTER TABLE announcement_audit_log ADD COLUMN reason TEXT NULL'
                );
            }
        }
        return;
    }

    // MySQL / MariaDB: idempotent via information_schema check.
    // Avoid the MariaDB-only `ADD COLUMN IF NOT EXISTS` syntax which is
    // not supported by MySQL 5.7 / MySQL 8.0.
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME   = 'announcement_audit_log'
            AND COLUMN_NAME  = 'reason'"
    );
    $stmt->execute();
    $exists = (int) $stmt->fetchColumn() > 0;

    if (!$exists) {
        $pdo->exec(
            'ALTER TABLE announcement_audit_log
             ADD COLUMN `reason` TEXT NULL
             AFTER `action`'
        );
    }
};
