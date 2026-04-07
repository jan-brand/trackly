<?php

declare(strict_types=1);

return function (PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS settings (
            `key`               VARCHAR(100) NOT NULL,
            `value_json`        JSON         NOT NULL,
            `updated_by_user_id` BIGINT      NOT NULL,
            `updated_at`        DATETIME     NOT NULL,
            PRIMARY KEY (`key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
};
