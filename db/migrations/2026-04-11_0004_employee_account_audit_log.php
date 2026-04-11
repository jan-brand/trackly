<?php

declare(strict_types=1);

/**
 * Stores immutable audit rows for employee profile/account mutations.
 */
return function (PDO $pdo): void {
    $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

    if ($driver === 'sqlite') {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS employee_account_audit_log (
                id             INTEGER PRIMARY KEY AUTOINCREMENT,
                target_user_id INTEGER NOT NULL,
                actor_user_id  INTEGER NOT NULL,
                action         TEXT NOT NULL,
                reason         TEXT NULL,
                old_json       TEXT NULL,
                new_json       TEXT NOT NULL,
                created_at     TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )'
        );

        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_emp_audit_target_created ON employee_account_audit_log (target_user_id, created_at)');
        return;
    }

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS employee_account_audit_log (
            id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            target_user_id INT UNSIGNED    NOT NULL,
            actor_user_id  INT UNSIGNED    NOT NULL,
            action         VARCHAR(100)    NOT NULL,
            reason         VARCHAR(255)    NULL,
            old_json       JSON            NULL,
            new_json       JSON            NOT NULL,
            created_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            INDEX idx_emp_audit_target_created (target_user_id, created_at),
            CONSTRAINT fk_emp_audit_target FOREIGN KEY (target_user_id) REFERENCES users(id),
            CONSTRAINT fk_emp_audit_actor  FOREIGN KEY (actor_user_id)  REFERENCES users(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
};
