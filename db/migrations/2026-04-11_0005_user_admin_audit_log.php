<?php

declare(strict_types=1);

/**
 * Immutable audit log for employee self-service and admin/coordination account actions.
 */
return function (PDO $pdo): void {
    $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

    if ($driver === 'sqlite') {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS user_admin_audit_log (
                id            INTEGER PRIMARY KEY AUTOINCREMENT,
                actor_user_id INTEGER NOT NULL,
                target_user_id INTEGER NOT NULL,
                action        TEXT NOT NULL,
                reason        TEXT NULL,
                diff_json     TEXT NOT NULL,
                created_at    TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )'
        );

        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_user_admin_audit_target_created ON user_admin_audit_log (target_user_id, created_at)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_user_admin_audit_actor_created ON user_admin_audit_log (actor_user_id, created_at)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_user_admin_audit_action_created ON user_admin_audit_log (action, created_at)');
        return;
    }

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS user_admin_audit_log (
            id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            actor_user_id  INT UNSIGNED    NOT NULL,
            target_user_id INT UNSIGNED    NOT NULL,
            action         VARCHAR(64)     NOT NULL,
            reason         TEXT            NULL,
            diff_json      JSON            NOT NULL,
            created_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            INDEX idx_user_admin_audit_target_created (target_user_id, created_at),
            INDEX idx_user_admin_audit_actor_created (actor_user_id, created_at),
            INDEX idx_user_admin_audit_action_created (action, created_at),
            CONSTRAINT fk_user_admin_audit_actor  FOREIGN KEY (actor_user_id) REFERENCES users(id),
            CONSTRAINT fk_user_admin_audit_target FOREIGN KEY (target_user_id) REFERENCES users(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
};
