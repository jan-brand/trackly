<?php

declare(strict_types=1);

namespace App\Domain\Holiday;

use PDO;

/**
 * Persistence layer for holiday rows.
 *
 * Each row: date_local (YYYY-MM-DD), state, name, is_public_holiday (0/1).
 * PK: (date_local, state).
 * Semantics: insert if new, update name/is_public_holiday if exists.
 * All writes run inside a single transaction (idempotent by design).
 */
class HolidayRepository
{
    public function __construct(private readonly PDO $pdo) {}

    /**
     * Upsert an array of holiday rows atomically.
     *
     * @param array<int, array{date_local: string, state: string, name: string, is_public_holiday: int|bool}> $rows
     */
    public function upsertMany(array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'sqlite') {
            $sql =
                'INSERT INTO holidays (date_local, state, name, is_public_holiday)
                 VALUES (:date_local, :state, :name, :is_public_holiday)
                 ON CONFLICT(date_local, state) DO UPDATE SET
                     name               = excluded.name,
                     is_public_holiday  = excluded.is_public_holiday';
        } else {
            $sql =
                'INSERT INTO holidays (date_local, state, name, is_public_holiday)
                 VALUES (:date_local, :state, :name, :is_public_holiday)
                 ON DUPLICATE KEY UPDATE
                     name               = VALUES(name),
                     is_public_holiday  = VALUES(is_public_holiday)';
        }

        $this->pdo->beginTransaction();

        try {
            $stmt = $this->pdo->prepare($sql);

            foreach ($rows as $row) {
                $stmt->execute([
                    ':date_local'        => $row['date_local'],
                    ':state'             => $row['state'],
                    ':name'              => $row['name'],
                    ':is_public_holiday' => (int) $row['is_public_holiday'],
                ]);
            }

            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Returns a map of already-synced (state → list of years) combinations.
     *
     * Uses SUBSTR(date_local, 1, 4) so it works in both MySQL and SQLite.
     *
     * @return array<string, list<int>>  e.g. ['BY' => [2025, 2026], 'BE' => [2026]]
     */
    public function getSyncedYears(): array
    {
        $stmt = $this->pdo->query(
            "SELECT state, SUBSTR(date_local, 1, 4) AS yr
             FROM holidays
             GROUP BY state, SUBSTR(date_local, 1, 4)
             ORDER BY state, yr"
        );

        $result = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $result[$row['state']][] = (int) $row['yr'];
        }

        return $result;
    }
}
