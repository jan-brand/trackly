<?php

declare(strict_types=1);

return function (PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS announcements (
            `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id`           INT UNSIGNED    NOT NULL,
            `date_local`        DATE            NOT NULL,
            `planned_start_at`  DATETIME        NOT NULL,
            `planned_end_at`    DATETIME        NOT NULL,
            `break_minutes`     INT UNSIGNED    NOT NULL DEFAULT 0,
            `net_minutes`       INT UNSIGNED    NOT NULL,
            `reason`            TEXT            NOT NULL,
            `status`            VARCHAR(50)     NOT NULL DEFAULT 'pending_approval',
            `created_at`        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at`        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            INDEX `IDX_announcements_user_date` (`user_id`, `date_local`),
            CONSTRAINT `fk_announcements_user`
                FOREIGN KEY (`user_id`) REFERENCES `users`(`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
};
