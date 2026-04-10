<?php

declare(strict_types=1);

return function (PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS announcement_audit_log (
            `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `announcement_id`  BIGINT UNSIGNED NOT NULL,
            `actor_user_id`    INT UNSIGNED    NOT NULL,
            `action`           VARCHAR(50)     NOT NULL,
            `old_json`         JSON            NULL,
            `new_json`         JSON            NOT NULL,
            `created_at`       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            INDEX `IDX_announcement_audit_log_ann` (`announcement_id`, `created_at`),
            CONSTRAINT `fk_announcement_audit_log_ann`
                FOREIGN KEY (`announcement_id`) REFERENCES `announcements`(`id`),
            CONSTRAINT `fk_announcement_audit_log_actor`
                FOREIGN KEY (`actor_user_id`) REFERENCES `users`(`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
};
