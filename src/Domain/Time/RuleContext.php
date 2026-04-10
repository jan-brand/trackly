<?php

declare(strict_types=1);

namespace App\Domain\Time;

/**
 * Contextual data required by the Rule Engine to evaluate a time entry.
 *
 * Contains the relevant application settings and the other (non-cancelled)
 * time entries for the same user — needed for overlap detection.
 *
 * Also contains approved announcements for the same user/date — needed for
 * announcement matching rules (AN5.5).
 */
final class RuleContext
{
    /**
     * @param int                                                                   $maxShiftMinutes          work.max_shift_minutes
     * @param int                                                                   $breakRequired6hMinutes   adult.break_required_over_6h_minutes
     * @param int                                                                   $breakRequired9hMinutes   adult.break_required_over_9h_minutes
     * @param list<array{id: int, start_at: string, end_at: string}>                $otherEntries             Other non-cancelled entries for same user (excluding current)
     * @param int                                                                   $maxDailyRegularMinutes   adult.max_daily_regular_minutes (or youth equivalent)
     * @param int                                                                   $maxDeviationMinutes      announcement.max_deviation_minutes
     * @param list<array{id: int, planned_start_at: string, planned_end_at: string}> $approvedAnnouncements    Approved announcements for same user/date
     */
    public function __construct(
        public readonly int   $maxShiftMinutes,
        public readonly int   $breakRequired6hMinutes,
        public readonly int   $breakRequired9hMinutes,
        public readonly array $otherEntries,
        public readonly int   $maxDailyRegularMinutes   = 480,
        public readonly int   $maxDeviationMinutes      = 30,
        public readonly array $approvedAnnouncements    = [],
    ) {}
}
