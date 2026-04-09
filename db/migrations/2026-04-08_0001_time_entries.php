<?php

declare(strict_types=1);

return function (PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS time_entries (
            `id`                   BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
            `user_id`              INT UNSIGNED     NOT NULL,
            `date_local`           DATE             NOT NULL,
            `start_at`             DATETIME         NOT NULL,
            `end_at`               DATETIME         NOT NULL,
            `break_minutes`        INT UNSIGNED     NOT NULL DEFAULT 0,
            `net_minutes`          INT UNSIGNED     NOT NULL,
            `entry_source`         VARCHAR(50)      NOT NULL DEFAULT 'manual',
            `status`               VARCHAR(50)      NOT NULL DEFAULT 'pending',
            `approved_by_user_id`  INT UNSIGNED     NULL,
            `approved_at`          DATETIME         NULL,
            `cancelled_by_user_id` INT UNSIGNED     NULL,
            `cancelled_at`         DATETIME         NULL,
            `created_at`           DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at`           DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            INDEX `IDX_time_entries_user_date` (`user_id`, `date_local`),
            CONSTRAINT `fk_time_entries_user`
                FOREIGN KEY (`user_id`) REFERENCES `users`(`id`),
            CONSTRAINT `fk_time_entries_approved_by`
                FOREIGN KEY (`approved_by_user_id`) REFERENCES `users`(`id`),
            CONSTRAINT `fk_time_entries_cancelled_by`
                FOREIGN KEY (`cancelled_by_user_id`) REFERENCES `users`(`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
};
