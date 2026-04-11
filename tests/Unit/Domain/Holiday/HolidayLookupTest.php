<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Holiday;

use App\Domain\Holiday\HolidayLookup;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * H7.4 – HolidayLookup::isHoliday() unit tests.
 *
 * Uses an in-memory SQLite DB seeded with the minimal Neujahr row for BE.
 */
class HolidayLookupTest extends TestCase
{
    private PDO $pdo;
    private HolidayLookup $lookup;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $this->pdo->exec(
            'CREATE TABLE holidays (
                date_local        TEXT    NOT NULL,
                state             TEXT    NOT NULL,
                name              TEXT    NOT NULL,
                is_public_holiday INTEGER NOT NULL DEFAULT 0,
                PRIMARY KEY (date_local, state)
            )'
        );

        // Seed: Neujahr 2026-01-01 for BE (is_public_holiday = 1)
        $this->pdo->exec(
            "INSERT INTO holidays (date_local, state, name, is_public_holiday)
             VALUES ('2026-01-01', 'BE', 'Neujahr', 1)"
        );

        $this->lookup = new HolidayLookup($this->pdo);
    }

    // -------------------------------------------------------------------------
    // H7.4 – 2026-01-01 BE seeded ⇒ true
    // -------------------------------------------------------------------------

    public function testKnownPublicHolidayReturnsTrue(): void
    {
        $this->assertTrue($this->lookup->isHoliday('2026-01-01', 'BE'));
    }

    // -------------------------------------------------------------------------
    // H7.4 – 2026-01-02 BE ⇒ false (no row)
    // -------------------------------------------------------------------------

    public function testUnknownDateReturnsFalse(): void
    {
        $this->assertFalse($this->lookup->isHoliday('2026-01-02', 'BE'));
    }

    // -------------------------------------------------------------------------
    // Extra: existing row with is_public_holiday = 0 ⇒ false
    // -------------------------------------------------------------------------

    public function testNonPublicHolidayReturnsFalse(): void
    {
        $this->pdo->exec(
            "INSERT INTO holidays (date_local, state, name, is_public_holiday)
             VALUES ('2026-01-03', 'BE', 'Kein Feiertag', 0)"
        );

        $this->assertFalse($this->lookup->isHoliday('2026-01-03', 'BE'));
    }
}
