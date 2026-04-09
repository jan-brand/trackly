<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Time;

use App\Domain\Time\TimeEntryValidationException;
use App\Domain\Time\TimeEntryValidator;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for TimeEntryValidator.
 *
 * T2.3 must-have cases:
 *   - end_time < start_time + allow_overnight=false ⇒ validation fails (422)
 *   - end_time < start_time + allow_overnight=true  ⇒ ok, end_at is date+1
 */
class TimeEntryValidatorTest extends TestCase
{
    private const BASE_INPUT = [
        'date'          => '2026-04-08',
        'start_time'    => '22:00',
        'end_time'      => '06:00',
        'break_minutes' => 30,
        'reason'        => 'Night shift test',
    ];

    // -------------------------------------------------------------------------
    // T2.3 must-have: overnight + allow_overnight=false ⇒ fails
    // -------------------------------------------------------------------------

    public function testOvernightShiftFailsWhenNotAllowed(): void
    {
        $validator = new TimeEntryValidator([
            'app.timezone'               => 'Europe/Berlin',
            'work.allow_overnight_shifts' => false,
            'work.max_shift_minutes'      => 600,
        ]);

        $this->expectException(TimeEntryValidationException::class);

        $validator->validate(self::BASE_INPUT);
    }

    public function testOvernightErrorIsInEndTimeField(): void
    {
        $validator = new TimeEntryValidator([
            'app.timezone'               => 'Europe/Berlin',
            'work.allow_overnight_shifts' => false,
            'work.max_shift_minutes'      => 600,
        ]);

        try {
            $validator->validate(self::BASE_INPUT);
            $this->fail('Expected TimeEntryValidationException');
        } catch (TimeEntryValidationException $e) {
            $this->assertArrayHasKey('end_time', $e->errors);
        }
    }

    // -------------------------------------------------------------------------
    // T2.3 must-have: overnight + allow_overnight=true ⇒ ok, end_at is date+1
    // -------------------------------------------------------------------------

    public function testOvernightShiftSucceedsWhenAllowed(): void
    {
        $validator = new TimeEntryValidator([
            'app.timezone'               => 'Europe/Berlin',
            'work.allow_overnight_shifts' => true,
            'work.max_shift_minutes'      => 600,
        ]);

        $result = $validator->validate(self::BASE_INPUT);

        $this->assertSame('2026-04-08', $result['date_local']);
        $this->assertStringStartsWith('2026-04-08', $result['start_at']);
        // end_at must be on the next day
        $this->assertStringStartsWith('2026-04-09', $result['end_at']);
    }

    public function testOvernightEndAtIsExactlyDatePlusOne(): void
    {
        $validator = new TimeEntryValidator([
            'app.timezone'               => 'Europe/Berlin',
            'work.allow_overnight_shifts' => true,
            'work.max_shift_minutes'      => 600,
        ]);

        $result = $validator->validate(self::BASE_INPUT);

        $this->assertSame('2026-04-09 06:00:00', $result['end_at']);
        $this->assertSame('2026-04-08 22:00:00', $result['start_at']);
    }

    // -------------------------------------------------------------------------
    // Net minutes and date_local derivation
    // -------------------------------------------------------------------------

    public function testNetMinutesAreComputedCorrectly(): void
    {
        $validator = new TimeEntryValidator([
            'app.timezone'               => 'Europe/Berlin',
            'work.allow_overnight_shifts' => false,
            'work.max_shift_minutes'      => 600,
        ]);

        $result = $validator->validate([
            'date'          => '2026-04-08',
            'start_time'    => '09:00',
            'end_time'      => '17:30',
            'break_minutes' => 30,
            'reason'        => 'Normal shift',
        ]);

        // shift = 510 min, break = 30 → net = 480
        $this->assertSame(480, $result['net_minutes']);
        $this->assertSame(30, $result['break_minutes']);
        $this->assertSame('2026-04-08', $result['date_local']);
    }

    // -------------------------------------------------------------------------
    // Field validation errors
    // -------------------------------------------------------------------------

    public function testMissingRequiredFieldsThrows(): void
    {
        $validator = new TimeEntryValidator();

        $this->expectException(TimeEntryValidationException::class);

        $validator->validate([]);
    }

    public function testReasonTooShortThrows(): void
    {
        $validator = new TimeEntryValidator([
            'app.timezone'               => 'Europe/Berlin',
            'work.allow_overnight_shifts' => false,
            'work.max_shift_minutes'      => 600,
        ]);

        try {
            $validator->validate([
                'date'          => '2026-04-08',
                'start_time'    => '09:00',
                'end_time'      => '17:00',
                'break_minutes' => 0,
                'reason'        => 'ab',
            ]);
            $this->fail('Expected TimeEntryValidationException');
        } catch (TimeEntryValidationException $e) {
            $this->assertArrayHasKey('reason', $e->errors);
        }
    }

    public function testBreakMinutesExceedsShiftThrows(): void
    {
        $validator = new TimeEntryValidator([
            'app.timezone'               => 'Europe/Berlin',
            'work.allow_overnight_shifts' => false,
            'work.max_shift_minutes'      => 600,
        ]);

        try {
            $validator->validate([
                'date'          => '2026-04-08',
                'start_time'    => '09:00',
                'end_time'      => '10:00',
                'break_minutes' => 120,   // more than 60-min shift
                'reason'        => 'Break exceeds shift',
            ]);
            $this->fail('Expected TimeEntryValidationException');
        } catch (TimeEntryValidationException $e) {
            $this->assertArrayHasKey('break_minutes', $e->errors);
        }
    }

    public function testShiftExceedsMaxThrows(): void
    {
        $validator = new TimeEntryValidator([
            'app.timezone'               => 'Europe/Berlin',
            'work.allow_overnight_shifts' => true,
            'work.max_shift_minutes'      => 60,  // very short max
        ]);

        try {
            $validator->validate([
                'date'          => '2026-04-08',
                'start_time'    => '09:00',
                'end_time'      => '17:00',
                'break_minutes' => 0,
                'reason'        => 'Shift too long',
            ]);
            $this->fail('Expected TimeEntryValidationException');
        } catch (TimeEntryValidationException $e) {
            $this->assertArrayHasKey('_global', $e->errors);
        }
    }

    public function testNegativeBreakMinutesThrows(): void
    {
        $validator = new TimeEntryValidator([
            'app.timezone'               => 'Europe/Berlin',
            'work.allow_overnight_shifts' => false,
            'work.max_shift_minutes'      => 600,
        ]);

        try {
            $validator->validate([
                'date'          => '2026-04-08',
                'start_time'    => '09:00',
                'end_time'      => '17:00',
                'break_minutes' => -1,
                'reason'        => 'Negative break',
            ]);
            $this->fail('Expected TimeEntryValidationException');
        } catch (TimeEntryValidationException $e) {
            $this->assertArrayHasKey('break_minutes', $e->errors);
        }
    }
}
