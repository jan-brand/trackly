<?php

declare(strict_types=1);

return function (PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS announcements (
            `id`                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `title`                VARCHAR(255)    NOT NULL,
            `body`                 TEXT            NOT NULL,
            `published_at`         DATETIME        NULL,
            `expires_at`           DATETIME        NULL,
            `created_by_user_id`   INT UNSIGNED    NOT NULL,
            `updated_by_user_id`   INT UNSIGNED    NULL,
            `created_at`           DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at`           DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            INDEX `IDX_announcements_published` (`published_at`),
            CONSTRAINT `fk_announcements_created_by`
                FOREIGN KEY (`created_by_user_id`) REFERENCES `users`(`id`),
            CONSTRAINT `fk_announcements_updated_by`
                FOREIGN KEY (`updated_by_user_id`) REFERENCES `users`(`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
};
