<?php

declare(strict_types=1);

/**
 * Minimal holiday seed: Neujahr for state BE (Berlin/Brandenburg).
 * Idempotent: uses ON DUPLICATE KEY UPDATE (MySQL) / ON CONFLICT DO UPDATE (SQLite).
 */
return function (PDO $pdo): void {
    $holidays = [
        ['date_local' => '2026-01-01', 'state' => 'BE', 'name' => 'Neujahr', 'is_public_holiday' => 1],
    ];

    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

    if ($driver === 'sqlite') {
        $stmt = $pdo->prepare(
            'INSERT INTO holidays (date_local, state, name, is_public_holiday)
             VALUES (:date_local, :state, :name, :is_public_holiday)
             ON CONFLICT(date_local, state) DO UPDATE SET
                 name               = excluded.name,
                 is_public_holiday  = excluded.is_public_holiday'
        );
    } else {
        $stmt = $pdo->prepare(
            'INSERT INTO holidays (date_local, state, name, is_public_holiday)
             VALUES (:date_local, :state, :name, :is_public_holiday)
             ON DUPLICATE KEY UPDATE
                 name               = VALUES(name),
                 is_public_holiday  = VALUES(is_public_holiday)'
        );
    }

    foreach ($holidays as $holiday) {
        $stmt->execute($holiday);
    }
};
