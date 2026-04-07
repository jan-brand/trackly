<?php

declare(strict_types=1);

/**
 * National holidays for Germany (state = 'DE').
 * Idempotent: uses ON DUPLICATE KEY UPDATE to upsert by PK(date, state).
 */
return function (PDO $pdo): void {
    $holidays = [
        ['date' => '2026-01-01', 'state' => 'DE', 'name' => 'Neujahr'],
        ['date' => '2026-04-03', 'state' => 'DE', 'name' => 'Karfreitag'],
        ['date' => '2026-04-06', 'state' => 'DE', 'name' => 'Ostermontag'],
        ['date' => '2026-05-01', 'state' => 'DE', 'name' => 'Tag der Arbeit'],
        ['date' => '2026-05-14', 'state' => 'DE', 'name' => 'Christi Himmelfahrt'],
        ['date' => '2026-05-25', 'state' => 'DE', 'name' => 'Pfingstmontag'],
        ['date' => '2026-10-03', 'state' => 'DE', 'name' => 'Tag der Deutschen Einheit'],
        ['date' => '2026-12-25', 'state' => 'DE', 'name' => '1. Weihnachtstag'],
        ['date' => '2026-12-26', 'state' => 'DE', 'name' => '2. Weihnachtstag'],
    ];

    $stmt = $pdo->prepare(
        'INSERT INTO holidays (`date`, `state`, `name`) VALUES (:date, :state, :name)
         ON DUPLICATE KEY UPDATE `name` = VALUES(`name`)'
    );

    foreach ($holidays as $holiday) {
        $stmt->execute($holiday);
    }
};
