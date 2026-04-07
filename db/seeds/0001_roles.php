<?php

declare(strict_types=1);

return function (PDO $pdo): void {
    $roles = [
        ['key' => 'employee',     'name' => 'Mitarbeiter'],
        ['key' => 'coordination', 'name' => 'Koordination'],
        ['key' => 'treasurer',    'name' => 'Schatzmeister'],
        ['key' => 'admin',        'name' => 'Administrator'],
    ];

    $stmt = $pdo->prepare(
        'INSERT INTO roles (`key`, name) VALUES (:key, :name)
         ON DUPLICATE KEY UPDATE name = VALUES(name)'
    );

    foreach ($roles as $role) {
        $stmt->execute($role);
    }
};
