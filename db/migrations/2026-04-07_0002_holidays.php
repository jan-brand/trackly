<?php

declare(strict_types=1);

return function (PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS holidays (
            `date_local`        DATE         NOT NULL,
            `state`             VARCHAR(10)  NOT NULL,
            `name`              VARCHAR(255) NOT NULL,
            `is_public_holiday` TINYINT(1)   NOT NULL DEFAULT 0,
            PRIMARY KEY (`date_local`, `state`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
};
