<?php

declare(strict_types=1);

return function (PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS settings (
            `key`               VARCHAR(100) NOT NULL,
            `value_json`        JSON         NOT NULL,
            `updated_by_user_id` INT UNSIGNED NOT NULL,
            `updated_at`        DATETIME     NOT NULL,
            PRIMARY KEY (`key`),
            CONSTRAINT fk_settings_user FOREIGN KEY (`updated_by_user_id`) REFERENCES `users`(`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
};
