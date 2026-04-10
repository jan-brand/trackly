<?php

declare(strict_types=1);

return function (PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS export_log (
            id             BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
            actor_user_id  INT UNSIGNED     NOT NULL,
            export_type    VARCHAR(10)      NOT NULL COMMENT 'csv|pdf',
            scope          VARCHAR(20)      NOT NULL COMMENT 'self|single_user|all_users',
            target_user_id INT UNSIGNED     NULL,
            month          CHAR(7)          NOT NULL COMMENT 'YYYY-MM',
            row_count      INT UNSIGNED     NOT NULL DEFAULT 0,
            status         VARCHAR(10)      NOT NULL DEFAULT 'ok' COMMENT 'ok|empty',
            created_at     DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            CONSTRAINT fk_export_log_actor  FOREIGN KEY (actor_user_id)  REFERENCES users(id),
            CONSTRAINT fk_export_log_target FOREIGN KEY (target_user_id) REFERENCES users(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
};
