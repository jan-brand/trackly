<?php

declare(strict_types=1);

namespace App\Domain\Holiday;

use PDO;

/**
 * Read-only helper that determines whether a given date is a public holiday
 * in a specific state, based solely on the `holidays` DB table (no HTTP).
 */
class HolidayLookup
{
    public function __construct(private readonly PDO $pdo) {}

    /**
     * Return true iff a row exists for (date_local, state) with is_public_holiday = 1.
     *
     * No timezone conversion is performed; $dateLocal must already be in local date form.
     */
    public function isHoliday(string $dateLocal, string $state): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT is_public_holiday
               FROM holidays
              WHERE date_local = :date_local
                AND state      = :state'
        );
        $stmt->execute([':date_local' => $dateLocal, ':state' => $state]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return false;
        }

        return (int) $row['is_public_holiday'] === 1;
    }
}
