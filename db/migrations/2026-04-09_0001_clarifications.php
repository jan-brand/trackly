<?php

declare(strict_types=1);

return function (PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS clarifications (
            `id`                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `time_entry_id`        BIGINT UNSIGNED NOT NULL,
            `asked_by_user_id`     INT UNSIGNED    NOT NULL,
            `question_text`        TEXT            NOT NULL,
            `status`               VARCHAR(50)     NOT NULL DEFAULT 'open',
            `answered_by_user_id`  INT UNSIGNED    NULL,
            `answer_text`          TEXT            NULL,
            `created_at`           DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `answered_at`          DATETIME        NULL,
            PRIMARY KEY (`id`),
            INDEX `IDX_clarifications_entry_status` (`time_entry_id`, `status`),
            INDEX `IDX_clarifications_entry_created` (`time_entry_id`, `created_at`),
            CONSTRAINT `fk_clarifications_entry`
                FOREIGN KEY (`time_entry_id`) REFERENCES `time_entries`(`id`),
            CONSTRAINT `fk_clarifications_asked_by`
                FOREIGN KEY (`asked_by_user_id`) REFERENCES `users`(`id`),
            CONSTRAINT `fk_clarifications_answered_by`
                FOREIGN KEY (`answered_by_user_id`) REFERENCES `users`(`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
};
