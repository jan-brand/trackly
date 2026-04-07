<?php

declare(strict_types=1);

return function (PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS users (
            id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            email        VARCHAR(255) NOT NULL,
            password_hash VARCHAR(255) NOT NULL,
            is_active    TINYINT(1) NOT NULL DEFAULT 1,
            created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_users_email (email)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS roles (
            id   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `key` VARCHAR(100) NOT NULL,
            name VARCHAR(255) NOT NULL,
            UNIQUE KEY uq_roles_key (`key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS user_roles (
            user_id INT UNSIGNED NOT NULL,
            role_id INT UNSIGNED NOT NULL,
            PRIMARY KEY (user_id, role_id),
            CONSTRAINT fk_user_roles_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_user_roles_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS employee_profiles (
            user_id      INT UNSIGNED NOT NULL,
            display_name VARCHAR(255) NOT NULL DEFAULT '',
            created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id),
            CONSTRAINT fk_employee_profiles_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
};
