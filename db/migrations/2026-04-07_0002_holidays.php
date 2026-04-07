<?php

declare(strict_types=1);

return function (PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS holidays (
            `date`  DATE         NOT NULL,
            `state` VARCHAR(10)  NOT NULL,
            `name`  VARCHAR(255) NOT NULL,
            PRIMARY KEY (`date`, `state`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
};
