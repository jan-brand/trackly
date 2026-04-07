<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Settings;

use App\Domain\Settings\SettingsValidator;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for SettingsValidator::validateSettingsPayload().
 *
 * Covers:
 *   – Unknown key  → per-field error, result is invalid
 *   – Per-field type/range errors
 *   – Cross-field constraints → errors._global
 *   – Happy-path: valid payload normalises correctly
 */
class SettingsValidatorTest extends TestCase
{
    private SettingsValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new SettingsValidator();
    }

    // -------------------------------------------------------------------------
    // Unknown keys
    // -------------------------------------------------------------------------

    public function testUnknownKeyProducesError(): void
    {
        $result = $this->validator->validateSettingsPayload([
            'does.not.exist' => 'whatever',
        ]);

        $this->assertFalse($result->isValid());
        $this->assertArrayHasKey('does.not.exist', $result->errors);
        $this->assertNotEmpty($result->errors['does.not.exist']);
    }

    public function testMultipleUnknownKeysEachGetTheirOwnError(): void
    {
        $result = $this->validator->validateSettingsPayload([
            'foo' => 1,
            'bar' => 2,
        ]);

        $this->assertFalse($result->isValid());
        $this->assertArrayHasKey('foo', $result->errors);
        $this->assertArrayHasKey('bar', $result->errors);
    }

    public function testUnknownKeyAlongsideValidKeyStillFails(): void
    {
        $result = $this->validator->validateSettingsPayload([
            'adult.max_daily_regular_minutes' => 480,
            'unknown.key'                     => 99,
        ]);

        $this->assertFalse($result->isValid());
        $this->assertArrayHasKey('unknown.key', $result->errors);
    }

    // -------------------------------------------------------------------------
    // Per-field validation
    // -------------------------------------------------------------------------

    public function testIntFieldRejectsNonNumericValue(): void
    {
        $result = $this->validator->validateSettingsPayload([
            'adult.max_daily_regular_minutes' => 'not-a-number',
        ]);

        $this->assertFalse($result->isValid());
        $this->assertArrayHasKey('adult.max_daily_regular_minutes', $result->errors);
    }

    public function testIntFieldRejectsValueBelowMin(): void
    {
        $result = $this->validator->validateSettingsPayload([
            'adult.max_daily_regular_minutes' => -1,
        ]);

        $this->assertFalse($result->isValid());
        $this->assertArrayHasKey('adult.max_daily_regular_minutes', $result->errors);
    }

    public function testIntFieldRejectsValueAboveMax(): void
    {
        $result = $this->validator->validateSettingsPayload([
            'adult.max_daily_regular_minutes' => 9999,
        ]);

        $this->assertFalse($result->isValid());
        $this->assertArrayHasKey('adult.max_daily_regular_minutes', $result->errors);
    }

    public function testStringFieldWithBadRegexFails(): void
    {
        $result = $this->validator->validateSettingsPayload([
            'youth.allowed_start_time' => 'not-a-time',
        ]);

        $this->assertFalse($result->isValid());
        $this->assertArrayHasKey('youth.allowed_start_time', $result->errors);
    }

    // -------------------------------------------------------------------------
    // Cross-field constraints → errors._global
    // -------------------------------------------------------------------------

    public function testDailyExceptionLessThanRegularProducesGlobalError(): void
    {
        $result = $this->validator->validateSettingsPayload([
            'adult.max_daily_regular_minutes'   => 600,
            'adult.max_daily_exception_minutes' => 480, // violation: 480 < 600
        ]);

        $this->assertFalse($result->isValid());
        $this->assertArrayHasKey('_global', $result->errors);
        $this->assertNotEmpty($result->errors['_global']);
    }

    public function testWeeklyExceptionLessThanRegularProducesGlobalError(): void
    {
        $result = $this->validator->validateSettingsPayload([
            'adult.max_weekly_regular_minutes'   => 2700,
            'adult.max_weekly_exception_minutes' => 2400, // violation
        ]);

        $this->assertFalse($result->isValid());
        $this->assertArrayHasKey('_global', $result->errors);
    }

    public function testBreakOver9hLessThanBreakOver6hProducesGlobalError(): void
    {
        $result = $this->validator->validateSettingsPayload([
            'adult.break_required_over_6h_minutes' => 45,
            'adult.break_required_over_9h_minutes' => 30, // violation
        ]);

        $this->assertFalse($result->isValid());
        $this->assertArrayHasKey('_global', $result->errors);
    }

    public function testYouthStartTimeNotBeforeEndTimeProducesGlobalError(): void
    {
        $result = $this->validator->validateSettingsPayload([
            'youth.allowed_start_time'           => '22:00',
            'youth.allowed_end_time'             => '06:00', // violation: start >= end
            'youth.allowed_end_time_exception'   => '22:00',
        ]);

        $this->assertFalse($result->isValid());
        $this->assertArrayHasKey('_global', $result->errors);
    }

    public function testYouthEndTimeAfterExceptionEndTimeProducesGlobalError(): void
    {
        $result = $this->validator->validateSettingsPayload([
            'youth.allowed_start_time'           => '06:00',
            'youth.allowed_end_time'             => '23:00', // violation: end > end_exception
            'youth.allowed_end_time_exception'   => '22:00',
        ]);

        $this->assertFalse($result->isValid());
        $this->assertArrayHasKey('_global', $result->errors);
    }

    public function testEqualEndTimeAndExceptionEndTimeIsAllowed(): void
    {
        $result = $this->validator->validateSettingsPayload([
            'youth.allowed_start_time'           => '06:00',
            'youth.allowed_end_time'             => '22:00',
            'youth.allowed_end_time_exception'   => '22:00', // equal is OK (<= constraint)
        ]);

        $this->assertArrayNotHasKey('_global', $result->errors);
    }

    // -------------------------------------------------------------------------
    // Happy path
    // -------------------------------------------------------------------------

    public function testEmptyInputUsesDefaultsAndIsValid(): void
    {
        $result = $this->validator->validateSettingsPayload([]);

        $this->assertTrue($result->isValid());
        $this->assertSame(480,     $result->values['adult.max_daily_regular_minutes']);
        $this->assertSame(600,     $result->values['adult.max_daily_exception_minutes']);
        $this->assertSame('06:00', $result->values['youth.allowed_start_time']);
    }

    public function testValidPayloadNormalisesValues(): void
    {
        $result = $this->validator->validateSettingsPayload([
            'adult.max_daily_regular_minutes'      => '450',
            'adult.max_daily_exception_minutes'    => '540',
            'adult.max_weekly_regular_minutes'     => '2250',
            'adult.max_weekly_exception_minutes'   => '2700',
            'adult.break_required_over_6h_minutes' => '30',
            'adult.break_required_over_9h_minutes' => '45',
            'youth.allowed_start_time'             => '07:00',
            'youth.allowed_end_time'               => '19:00',
            'youth.allowed_end_time_exception'     => '21:00',
        ]);

        $this->assertTrue($result->isValid(), json_encode($result->errors));
        $this->assertSame(450, $result->values['adult.max_daily_regular_minutes']);
        $this->assertSame(540, $result->values['adult.max_daily_exception_minutes']);
        $this->assertSame('07:00', $result->values['youth.allowed_start_time']);
    }

    public function testPartialPayloadMergesWithDefaults(): void
    {
        $result = $this->validator->validateSettingsPayload([
            'adult.max_daily_regular_minutes' => 400,
        ]);

        $this->assertTrue($result->isValid());
        $this->assertSame(400, $result->values['adult.max_daily_regular_minutes']);
        // Other values come from defaults; exception >= regular (600 >= 400) is still satisfied.
        $this->assertSame(600, $result->values['adult.max_daily_exception_minutes']);
    }
}
