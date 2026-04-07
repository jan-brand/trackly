<?php

declare(strict_types=1);

return function (PDO $pdo, bool $resetAdminPassword = false): void {
    $email    = $_ENV['ADMIN_EMAIL']    ?? '';
    $password = $_ENV['ADMIN_PASSWORD'] ?? '';

    if ($email === '') {
        return;
    }

    $stmt = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
    $stmt->execute([':email' => $email]);
    $user = $stmt->fetch();

    if ($user === false) {
        if ($password === '') {
            throw new \RuntimeException('ADMIN_PASSWORD is required when creating the admin user.');
        }

        $hash = password_hash($password, PASSWORD_BCRYPT);

        $insert = $pdo->prepare(
            'INSERT INTO users (email, password_hash, is_active) VALUES (:email, :hash, 1)'
        );
        $insert->execute([':email' => $email, ':hash' => $hash]);
        $userId = (int) $pdo->lastInsertId();
    } else {
        $userId = (int) $user['id'];

        $pdo->prepare('UPDATE users SET is_active = 1 WHERE id = :id')
            ->execute([':id' => $userId]);

        if ($resetAdminPassword && $password !== '') {
            $hash = password_hash($password, PASSWORD_BCRYPT);
            $pdo->prepare('UPDATE users SET password_hash = :hash WHERE id = :id')
                ->execute([':hash' => $hash, ':id' => $userId]);
        }
    }

    $roleStmt = $pdo->prepare('SELECT id FROM roles WHERE `key` = :key LIMIT 1');
    $roleStmt->execute([':key' => 'admin']);
    $role = $roleStmt->fetch();

    if ($role !== false) {
        $pdo->prepare(
            'INSERT INTO user_roles (user_id, role_id) VALUES (:user_id, :role_id)
             ON DUPLICATE KEY UPDATE user_id = user_id'
        )->execute([':user_id' => $userId, ':role_id' => $role['id']]);
    }

    $pdo->prepare(
        'INSERT INTO employee_profiles (user_id, display_name) VALUES (:user_id, :name)
         ON DUPLICATE KEY UPDATE display_name = display_name'
    )->execute([':user_id' => $userId, ':name' => 'Admin']);
};
