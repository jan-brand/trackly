<?php

declare(strict_types=1);

namespace App\Domain\Time;

/**
 * Rule Engine v1 — evaluates a set of business rules against a time entry.
 *
 * Evaluation order (B4):
 *   B1  overlap           – entry overlaps another non-cancelled entry for same user
 *   B2  shift_too_long    – net shift > work.max_shift_minutes
 *   B3  break_too_short   – break < legal minimum for given shift length (6 h rule)
 *   B4  break_too_short_9h– break < legal minimum for >9 h shifts (9 h rule)
 *
 * Post-processing:
 *   1. Collect flags in rule order (earlier rules win on dedup).
 *   2. Remove duplicate flag_keys (first occurrence kept).
 *   3. Sort remaining flags by flag_key (stable, deterministic output).
 *
 * @see RuleContext
 * @see Flag
 */
final class RuleEngine
{
    /**
     * Evaluate all rules and return the sorted, deduplicated flag list.
     *
     * @param  TimeEntry   $entry  The entry to evaluate
     * @param  RuleContext $ctx    Settings and sibling entries for context
     * @return list<Flag>
     */
    public function evaluate(TimeEntry $entry, RuleContext $ctx): array
    {
        $accumulated = [];

        // B1 – Overlap
        $accumulated = array_merge($accumulated, $this->ruleOverlap($entry, $ctx));

        // B2 – Shift too long
        $accumulated = array_merge($accumulated, $this->ruleShiftTooLong($entry, $ctx));

        // B3 – Break too short (≥ 6 h shift)
        $accumulated = array_merge($accumulated, $this->ruleBreakTooShort6h($entry, $ctx));

        // B4 – Break too short (≥ 9 h shift)
        $accumulated = array_merge($accumulated, $this->ruleBreakTooShort9h($entry, $ctx));

        return $this->sortAndDeduplicate($accumulated);
    }

    // -------------------------------------------------------------------------
    // Rules
    // -------------------------------------------------------------------------

    /** @return list<Flag> */
    private function ruleOverlap(TimeEntry $entry, RuleContext $ctx): array
    {
        foreach ($ctx->otherEntries as $other) {
            // Strict overlap: A.start < B.end AND A.end > B.start
            // Boundary (end == start) is NOT an overlap.
            if ($entry->startAt < $other['end_at'] && $entry->endAt > $other['start_at']) {
                return [new Flag('overlap', (string) $other['id'])];
            }
        }
        return [];
    }

    /** @return list<Flag> */
    private function ruleShiftTooLong(TimeEntry $entry, RuleContext $ctx): array
    {
        if ($entry->netMinutes + $entry->breakMinutes > $ctx->maxShiftMinutes) {
            return [new Flag('shift_too_long', (string) ($entry->netMinutes + $entry->breakMinutes))];
        }
        return [];
    }

    /** @return list<Flag> */
    private function ruleBreakTooShort6h(TimeEntry $entry, RuleContext $ctx): array
    {
        $shiftMinutes = $entry->netMinutes + $entry->breakMinutes;

        if ($shiftMinutes > 360 && $entry->breakMinutes < $ctx->breakRequired6hMinutes) {
            return [new Flag('break_too_short', (string) $entry->breakMinutes)];
        }
        return [];
    }

    /** @return list<Flag> */
    private function ruleBreakTooShort9h(TimeEntry $entry, RuleContext $ctx): array
    {
        $shiftMinutes = $entry->netMinutes + $entry->breakMinutes;

        if ($shiftMinutes > 540 && $entry->breakMinutes < $ctx->breakRequired9hMinutes) {
            return [new Flag('break_too_short', (string) $entry->breakMinutes)];
        }
        return [];
    }

    // -------------------------------------------------------------------------
    // Post-processing
    // -------------------------------------------------------------------------

    /**
     * Deduplicate flags (first rule's flag wins) then sort by flag_key.
     *
     * @param  list<Flag> $flags  Flags in rule-evaluation order
     * @return list<Flag>
     */
    private function sortAndDeduplicate(array $flags): array
    {
        // Deduplicate: preserve first occurrence per flag_key.
        $seen    = [];
        $deduped = [];
        foreach ($flags as $flag) {
            if (!isset($seen[$flag->flagKey])) {
                $seen[$flag->flagKey] = true;
                $deduped[]            = $flag;
            }
        }

        // Stable sort by flag_key for deterministic output.
        usort($deduped, static fn(Flag $a, Flag $b): int => strcmp($a->flagKey, $b->flagKey));

        return $deduped;
    }
}
