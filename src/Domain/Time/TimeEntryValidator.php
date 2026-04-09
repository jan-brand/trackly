<?php

declare(strict_types=1);

namespace App\Domain\Time;

/**
 * Validates and derives a manual time-entry input payload.
 *
 * Input fields:
 *   - date          (YYYY-MM-DD)
 *   - start_time    (HH:MM)
 *   - end_time      (HH:MM)
 *   - break_minutes (int >= 0)
 *   - reason        (string, min 3 chars)
 *
 * Derived output fields (ready for TimeEntryService::createManual):
 *   - date_local    (same as `date` input — start day counts)
 *   - start_at      (DATETIME in app.timezone, e.g. "2026-04-08 09:00:00")
 *   - end_at        (DATETIME in app.timezone; next day when overnight)
 *   - break_minutes
 *   - net_minutes   (shift_minutes - break_minutes)
 *   - reason
 *
 * @throws TimeEntryValidationException on any validation failure
 */
final class TimeEntryValidator
{
    /**
     * @param array<string, mixed> $settings  Application settings (see SettingsRegistry)
     */
    public function __construct(private readonly array $settings = []) {}

    /**
     * Validate raw input and return the derived payload.
     *
     * @param  array<string, mixed> $input  Raw form/API input
     * @return array<string, mixed>         Derived fields ready for TimeEntryService
     *
     * @throws TimeEntryValidationException
     */
    public function validate(array $input): array
    {
        /** @var array<string, list<string>> $errors */
        $errors = [];

        // ------------------------------------------------------------------
        // 1. Field presence and basic format checks
        // ------------------------------------------------------------------
        $date         = $this->requireString($input, 'date', $errors);
        $startTime    = $this->requireString($input, 'start_time', $errors);
        $endTime      = $this->requireString($input, 'end_time', $errors);
        $breakMinutes = $this->requireInt($input, 'break_minutes', $errors);
        $reason       = $this->requireString($input, 'reason', $errors);

        if ($date !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $errors['date'][] = 'date must be in YYYY-MM-DD format.';
            $date = null;
        }

        if ($startTime !== null && !preg_match('/^\d{2}:\d{2}$/', $startTime)) {
            $errors['start_time'][] = 'start_time must be in HH:MM format.';
            $startTime = null;
        }

        if ($endTime !== null && !preg_match('/^\d{2}:\d{2}$/', $endTime)) {
            $errors['end_time'][] = 'end_time must be in HH:MM format.';
            $endTime = null;
        }

        if ($breakMinutes !== null && $breakMinutes < 0) {
            $errors['break_minutes'][] = 'break_minutes must be >= 0.';
            $breakMinutes = null;
        }

        if ($reason !== null && mb_strlen($reason) < 3) {
            $errors['reason'][] = 'reason must be at least 3 characters.';
            $reason = null;
        }

        // Bail early if basic validation failed
        if ($errors !== []) {
            throw new TimeEntryValidationException($errors);
        }

        // ------------------------------------------------------------------
        // 2. Timezone + DATETIME derivation
        // ------------------------------------------------------------------
        $timezone      = $this->settings['app.timezone'] ?? 'Europe/Berlin';
        $allowOvernight = (bool) ($this->settings['work.allow_overnight_shifts'] ?? false);
        $maxShiftMinutes = (int) ($this->settings['work.max_shift_minutes'] ?? 600);

        try {
            $tz = new \DateTimeZone($timezone);
        } catch (\Exception) {
            $tz = new \DateTimeZone('Europe/Berlin');
        }

        $startAt = \DateTimeImmutable::createFromFormat('Y-m-d H:i', "{$date} {$startTime}", $tz);
        if ($startAt === false) {
            $errors['start_time'][] = 'Could not parse start_time.';
            throw new TimeEntryValidationException($errors);
        }

        // HH:MM string comparison is correct here: both values are zero-padded
        // (validated by regex above), so lexicographic order equals chronological order.
        $isOvernight = $endTime < $startTime;

        if ($isOvernight) {
            if (!$allowOvernight) {
                $errors['end_time'][] = 'end_time is before start_time and overnight shifts are not allowed.';
                throw new TimeEntryValidationException($errors);
            }
            // Overnight: end is on the next day
            $endDate = $startAt->modify('+1 day')->format('Y-m-d');
        } else {
            $endDate = $date;
        }

        $endAt = \DateTimeImmutable::createFromFormat('Y-m-d H:i', "{$endDate} {$endTime}", $tz);
        if ($endAt === false) {
            $errors['end_time'][] = 'Could not parse end_time.';
            throw new TimeEntryValidationException($errors);
        }

        // ------------------------------------------------------------------
        // 3. Cross-field guards
        // ------------------------------------------------------------------
        $shiftSeconds = $endAt->getTimestamp() - $startAt->getTimestamp();
        $shiftMinutes = (int) ($shiftSeconds / 60);

        if ($shiftMinutes <= 0) {
            $errors['end_time'][] = 'end_at must be after start_at.';
        }

        if ($shiftMinutes > 0 && $breakMinutes > $shiftMinutes) {
            $errors['break_minutes'][] = 'break_minutes must not exceed the total shift duration.';
        }

        if ($shiftMinutes > $maxShiftMinutes) {
            $errors['_global'][] = "Shift duration ({$shiftMinutes} min) exceeds the maximum allowed shift length ({$maxShiftMinutes} min).";
        }

        if ($errors !== []) {
            throw new TimeEntryValidationException($errors);
        }

        // ------------------------------------------------------------------
        // 4. Return derived payload
        // ------------------------------------------------------------------
        $netMinutes = $shiftMinutes - (int) $breakMinutes;

        return [
            'date_local'    => $date,
            'start_at'      => $startAt->format('Y-m-d H:i:s'),
            'end_at'        => $endAt->format('Y-m-d H:i:s'),
            'break_minutes' => (int) $breakMinutes,
            'net_minutes'   => max(0, $netMinutes),
            'reason'        => (string) $reason,
        ];
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * @param array<string, mixed>        $input
     * @param array<string, list<string>> $errors
     */
    private function requireString(array $input, string $field, array &$errors): ?string
    {
        if (!array_key_exists($field, $input) || $input[$field] === null || $input[$field] === '') {
            $errors[$field][] = "{$field} is required.";
            return null;
        }
        return (string) $input[$field];
    }

    /**
     * @param array<string, mixed>        $input
     * @param array<string, list<string>> $errors
     */
    private function requireInt(array $input, string $field, array &$errors): ?int
    {
        if (!array_key_exists($field, $input)) {
            $errors[$field][] = "{$field} is required.";
            return null;
        }
        if (!is_numeric($input[$field])) {
            $errors[$field][] = "{$field} must be an integer.";
            return null;
        }
        return (int) $input[$field];
    }
}
