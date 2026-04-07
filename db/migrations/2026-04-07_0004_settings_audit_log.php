<?php

declare(strict_types=1);

return function (PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS settings_audit_log (
            `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `changed_by_user_id`  BIGINT          NOT NULL,
            `reason`              VARCHAR(500)    NOT NULL,
            `snapshot_old`        JSON            NOT NULL,
            `snapshot_new`        JSON            NOT NULL,
            `created_at`          DATETIME        NOT NULL,
            PRIMARY KEY (`id`),
            CONSTRAINT fk_audit_log_user
                FOREIGN KEY (`changed_by_user_id`) REFERENCES `users`(`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
};
