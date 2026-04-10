<?php

declare(strict_types=1);

return function (PDO $pdo): void {
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

    if ($driver === 'sqlite') {
        // SQLite does not support ADD COLUMN IF NOT EXISTS – guard manually.
        $cols = $pdo->query('PRAGMA table_info(announcement_audit_log)')->fetchAll(PDO::FETCH_COLUMN, 1);
        if (!in_array('reason', $cols, true)) {
            $pdo->exec("ALTER TABLE announcement_audit_log ADD COLUMN reason TEXT NULL");
        }
    } else {
        $pdo->exec("
            ALTER TABLE announcement_audit_log
                ADD COLUMN IF NOT EXISTS `reason` TEXT NULL
                    AFTER `action`
        ");
    }
};
