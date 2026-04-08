<?php

declare(strict_types=1);

return function (PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS time_entry_audit_log (
            `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `time_entry_id` BIGINT UNSIGNED NOT NULL,
            `actor_user_id` INT UNSIGNED    NOT NULL,
            `action`        VARCHAR(50)     NOT NULL,
            `reason`        TEXT            NOT NULL,
            `old_json`      JSON            NULL,
            `new_json`      JSON            NOT NULL,
            `created_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            INDEX `IDX_time_entry_audit_log_entry` (`time_entry_id`, `created_at`),
            CONSTRAINT `fk_time_entry_audit_log_entry`
                FOREIGN KEY (`time_entry_id`) REFERENCES `time_entries`(`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
};
