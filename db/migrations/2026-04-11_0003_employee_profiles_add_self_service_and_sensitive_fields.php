<?php

declare(strict_types=1);

/**
 * Extends employee_profiles with self-service and coordination-only fields.
 *
 * Idempotent across MariaDB/MySQL and SQLite.
 */
return function (PDO $pdo): void {
    $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

    $hasColumn = static function (string $column) use ($pdo, $driver): bool {
        if ($driver === 'sqlite') {
            $stmt = $pdo->query('PRAGMA table_info(employee_profiles)');
            if ($stmt === false) {
                return false;
            }

            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if (($row['name'] ?? null) === $column) {
                    return true;
                }
            }

            return false;
        }

        $stmt = $pdo->prepare('SHOW COLUMNS FROM employee_profiles LIKE :column');
        $stmt->execute([':column' => $column]);
        return $stmt->fetch(PDO::FETCH_ASSOC) !== false;
    };

    $columns = [
        'first_name' => $driver === 'sqlite' ? 'TEXT NULL' : 'TEXT NULL',
        'last_name' => $driver === 'sqlite' ? 'TEXT NULL' : 'TEXT NULL',
        'address_text' => $driver === 'sqlite' ? 'TEXT NULL' : 'TEXT NULL',
        'study_subjects_text' => $driver === 'sqlite' ? 'TEXT NULL' : 'TEXT NULL',
        'study_program_text' => $driver === 'sqlite' ? 'TEXT NULL' : 'TEXT NULL',
        'expected_graduation_date' => $driver === 'sqlite' ? 'TEXT NULL' : 'DATE NULL',
        'birth_date' => $driver === 'sqlite' ? 'TEXT NULL' : 'DATE NULL',
        'weekly_target_minutes' => $driver === 'sqlite' ? 'INTEGER NULL' : 'INT NULL',
        'contract_type_key' => $driver === 'sqlite' ? 'TEXT NULL' : 'VARCHAR(100) NULL',
    ];

    foreach ($columns as $column => $definition) {
        if ($hasColumn($column)) {
            continue;
        }

        $pdo->exec('ALTER TABLE employee_profiles ADD COLUMN ' . $column . ' ' . $definition);
    }
};
