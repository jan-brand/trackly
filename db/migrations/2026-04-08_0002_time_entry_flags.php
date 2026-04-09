<?php

declare(strict_types=1);

return function (PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS time_entry_flags (
            `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `time_entry_id` BIGINT UNSIGNED NOT NULL,
            `flag_key`      VARCHAR(100)    NOT NULL,
            `flag_value`    TEXT            NULL,
            `created_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_time_entry_flags` (`time_entry_id`, `flag_key`),
            CONSTRAINT `fk_time_entry_flags_entry`
                FOREIGN KEY (`time_entry_id`) REFERENCES `time_entries`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
};
