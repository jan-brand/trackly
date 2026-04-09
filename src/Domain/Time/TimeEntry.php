<?php

declare(strict_types=1);

namespace App\Domain\Time;

/**
 * Immutable value object representing a time entry as seen by the Rule Engine.
 */
final class TimeEntry
{
    public function __construct(
        public readonly int    $id,
        public readonly int    $userId,
        public readonly string $dateLocal,
        public readonly string $startAt,
        public readonly string $endAt,
        public readonly int    $breakMinutes,
        public readonly int    $netMinutes,
        public readonly string $entrySource,
        public readonly string $status,
    ) {}

    /**
     * Construct from a time_entries DB row.
     *
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            id:           (int) $row['id'],
            userId:       (int) $row['user_id'],
            dateLocal:    (string) $row['date_local'],
            startAt:      (string) $row['start_at'],
            endAt:        (string) $row['end_at'],
            breakMinutes: (int) $row['break_minutes'],
            netMinutes:   (int) $row['net_minutes'],
            entrySource:  (string) $row['entry_source'],
            status:       (string) $row['status'],
        );
    }
}
