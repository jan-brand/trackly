<?php

declare(strict_types=1);

return function (PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS settings_audit_log (
            `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `actor_user_id`   INT UNSIGNED    NOT NULL,
            `action`          VARCHAR(50)     NOT NULL,
            `reason`          TEXT            NOT NULL,
            `old_value_json`  JSON            NULL,
            `new_value_json`  JSON            NOT NULL,
            `created_at`      DATETIME        NOT NULL,
            PRIMARY KEY (`id`),
            INDEX `IDX_settings_audit_created` (`actor_user_id`, `created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
};
