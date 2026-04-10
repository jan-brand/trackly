<?php

declare(strict_types=1);

namespace App\Domain\Announcement;

/**
 * Validates and derives an announcement input payload.
 *
 * Input fields:
 *   - date                (YYYY-MM-DD)
 *   - planned_start_time  (HH:MM)
 *   - planned_end_time    (HH:MM)
 *   - break_minutes       (int >= 0)
 *   - reason              (string, min 3 chars)
 *
 * Derived output fields:
 *   - date_local          (same as `date` input)
 *   - planned_start_at    (DATETIME in app.timezone)
 *   - planned_end_at      (DATETIME in app.timezone; next day when overnight)
 *   - break_minutes
 *   - net_minutes         (shift_minutes - break_minutes)
 *   - reason
 *
 * @throws AnnouncementValidationException on any validation failure
 */
final class AnnouncementValidator
{
    /**
     * @param array<string, mixed> $settings  Application settings (see SettingsRegistry)
     */
    public function __construct(private readonly array $settings = []) {}

    /**
     * Validate raw input and return the derived payload.
     *
     * @param  array<string, mixed> $input  Raw form input
     * @return array<string, mixed>         Derived fields ready for AnnouncementService
     *
     * @throws AnnouncementValidationException
     */
    public function validate(array $input): array
    {
        /** @var array<string, list<string>> $errors */
        $errors = [];

        // ------------------------------------------------------------------
        // 1. Field presence and basic format checks
        // ------------------------------------------------------------------
        $date             = $this->requireString($input, 'date', $errors);
        $plannedStartTime = $this->requireString($input, 'planned_start_time', $errors);
        $plannedEndTime   = $this->requireString($input, 'planned_end_time', $errors);
        $breakMinutes     = $this->requireInt($input, 'break_minutes', $errors);
        $reason           = $this->requireString($input, 'reason', $errors);

        if ($date !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $errors['date'][] = 'date must be in YYYY-MM-DD format.';
            $date = null;
        }

        if ($plannedStartTime !== null && !preg_match('/^\d{2}:\d{2}$/', $plannedStartTime)) {
            $errors['planned_start_time'][] = 'planned_start_time must be in HH:MM format.';
            $plannedStartTime = null;
        }

        if ($plannedEndTime !== null && !preg_match('/^\d{2}:\d{2}$/', $plannedEndTime)) {
            $errors['planned_end_time'][] = 'planned_end_time must be in HH:MM format.';
            $plannedEndTime = null;
        }

        if ($breakMinutes !== null && $breakMinutes < 0) {
            $errors['break_minutes'][] = 'break_minutes must be >= 0.';
            $breakMinutes = null;
        }

        if ($reason !== null && mb_strlen($reason) < 3) {
            $errors['reason'][] = 'reason must be at least 3 characters.';
            $reason = null;
        }

        if ($errors !== []) {
            throw new AnnouncementValidationException($errors);
        }

        // ------------------------------------------------------------------
        // 2. Timezone + DATETIME derivation
        // ------------------------------------------------------------------
        $timezone       = $this->settings['app.timezone'] ?? 'Europe/Berlin';
        $allowOvernight = (bool) ($this->settings['work.allow_overnight_shifts'] ?? false);

        try {
            $tz = new \DateTimeZone($timezone);
        } catch (\Exception) {
            $tz = new \DateTimeZone('Europe/Berlin');
        }

        $startAt = \DateTimeImmutable::createFromFormat('Y-m-d H:i', "{$date} {$plannedStartTime}", $tz);
        if ($startAt === false) {
            throw new AnnouncementValidationException(['date' => ['Invalid date or start time.']]);
        }

        // Determine end datetime – overnight if end <= start
        $endSameDay = \DateTimeImmutable::createFromFormat('Y-m-d H:i', "{$date} {$plannedEndTime}", $tz);
        if ($endSameDay === false) {
            throw new AnnouncementValidationException(['planned_end_time' => ['Invalid end time.']]);
        }

        $isOvernight = $endSameDay <= $startAt;

        if ($isOvernight && !$allowOvernight) {
            throw new AnnouncementValidationException([
                'planned_end_time' => ['Overnight shifts are not allowed (work.allow_overnight_shifts=false).'],
            ]);
        }

        $endAt = $isOvernight
            ? $endSameDay->modify('+1 day')
            : $endSameDay;

        // ------------------------------------------------------------------
        // 3. Net minutes
        // ------------------------------------------------------------------
        $shiftMinutes = (int) round(($endAt->getTimestamp() - $startAt->getTimestamp()) / 60);
        $netMinutes   = max(0, $shiftMinutes - (int) $breakMinutes);

        return [
            'date_local'       => $date,
            'planned_start_at' => $startAt->format('Y-m-d H:i:s'),
            'planned_end_at'   => $endAt->format('Y-m-d H:i:s'),
            'break_minutes'    => (int) $breakMinutes,
            'net_minutes'      => $netMinutes,
            'reason'           => $reason,
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
        $value = isset($input[$field]) ? trim((string) $input[$field]) : null;
        if ($value === null || $value === '') {
            $errors[$field][] = "{$field} is required.";
            return null;
        }
        return $value;
    }

    /**
     * @param array<string, mixed>        $input
     * @param array<string, list<string>> $errors
     */
    private function requireInt(array $input, string $field, array &$errors): ?int
    {
        if (!isset($input[$field]) || $input[$field] === '') {
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
