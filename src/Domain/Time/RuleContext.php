<?php

declare(strict_types=1);

namespace App\Domain\Time;

/**
 * Contextual data required by the Rule Engine to evaluate a time entry.
 *
 * Contains the relevant application settings and the other (non-cancelled)
 * time entries for the same user — needed for overlap detection.
 */
final class RuleContext
{
    /**
     * @param int                                              $maxShiftMinutes          work.max_shift_minutes
     * @param int                                              $breakRequired6hMinutes   adult.break_required_over_6h_minutes
     * @param int                                              $breakRequired9hMinutes   adult.break_required_over_9h_minutes
     * @param list<array{id: int, start_at: string, end_at: string}> $otherEntries Other non-cancelled entries for same user (excluding current)
     */
    public function __construct(
        public readonly int   $maxShiftMinutes,
        public readonly int   $breakRequired6hMinutes,
        public readonly int   $breakRequired9hMinutes,
        public readonly array $otherEntries,
    ) {}
}
