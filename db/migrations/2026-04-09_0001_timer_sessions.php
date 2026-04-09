<?php

declare(strict_types=1);

return function (PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS timer_sessions (
            `id`                   BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
            `user_id`              INT UNSIGNED     NOT NULL,
            `status`               VARCHAR(20)      NOT NULL DEFAULT 'running',
            `started_at`           DATETIME         NOT NULL,
            `paused_at`            DATETIME         NULL,
            `stopped_at`           DATETIME         NULL,
            `total_pause_seconds`  INT UNSIGNED     NOT NULL DEFAULT 0,
            `created_at`           DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at`           DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            INDEX `IDX_timer_sessions_user_status` (`user_id`, `status`),
            CONSTRAINT `fk_timer_sessions_user`
                FOREIGN KEY (`user_id`) REFERENCES `users`(`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
};
