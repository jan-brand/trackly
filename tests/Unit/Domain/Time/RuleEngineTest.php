<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Time;

use App\Domain\Time\Flag;
use App\Domain\Time\RuleContext;
use App\Domain\Time\RuleEngine;
use App\Domain\Time\TimeEntry;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for RuleEngine.
 *
 * T2.4 must-have cases:
 *   - Same inputs ⇒ exactly same flag_key order (determinism)
 *   - Boundary overlap: end == start ⇒ no overlap flag
 */
class RuleEngineTest extends TestCase
{
    private RuleEngine $engine;

    protected function setUp(): void
    {
        $this->engine = new RuleEngine();
    }

    // -------------------------------------------------------------------------
    // Helper factories
    // -------------------------------------------------------------------------

    private function makeEntry(
        int    $id = 1,
        int    $userId = 10,
        string $startAt = '2026-04-08 09:00:00',
        string $endAt   = '2026-04-08 17:00:00',
        int    $breakMinutes = 30,
        int    $netMinutes   = 450,
        string $status = 'pending',
    ): TimeEntry {
        return new TimeEntry(
            id:           $id,
            userId:       $userId,
            dateLocal:    substr($startAt, 0, 10),
            startAt:      $startAt,
            endAt:        $endAt,
            breakMinutes: $breakMinutes,
            netMinutes:   $netMinutes,
            entrySource:  'manual',
            status:       $status,
        );
    }

    private function makeContext(
        int   $maxShiftMinutes        = 600,
        int   $breakRequired6h        = 30,
        int   $breakRequired9h        = 45,
        array $otherEntries           = [],
        int   $maxDailyRegularMinutes = 480,
        int   $maxDeviationMinutes    = 30,
        array $approvedAnnouncements  = [],
    ): RuleContext {
        return new RuleContext(
            maxShiftMinutes:        $maxShiftMinutes,
            breakRequired6hMinutes: $breakRequired6h,
            breakRequired9hMinutes: $breakRequired9h,
            otherEntries:           $otherEntries,
            maxDailyRegularMinutes: $maxDailyRegularMinutes,
            maxDeviationMinutes:    $maxDeviationMinutes,
            approvedAnnouncements:  $approvedAnnouncements,
        );
    }

    // -------------------------------------------------------------------------
    // T2.4 must-have: same inputs ⇒ exact same flag_key order
    // -------------------------------------------------------------------------

    public function testSameInputsYieldSameFlagOrder(): void
    {
        // Entry that triggers shift_too_long (600 min shift > 600 max... 601 min)
        $entry = $this->makeEntry(
            startAt:      '2026-04-08 08:00:00',
            endAt:        '2026-04-08 18:01:00',
            breakMinutes: 0,
            netMinutes:   601,
        );
        $ctx = $this->makeContext(maxShiftMinutes: 600);

        $result1 = $this->engine->evaluate($entry, $ctx);
        $result2 = $this->engine->evaluate($entry, $ctx);

        $keys1 = array_map(fn(Flag $f) => $f->flagKey, $result1);
        $keys2 = array_map(fn(Flag $f) => $f->flagKey, $result2);

        $this->assertSame($keys1, $keys2);
    }

    // -------------------------------------------------------------------------
    // T2.4 must-have: boundary overlap — end == start ⇒ no overlap
    // -------------------------------------------------------------------------

    public function testBoundaryEndEqualsStartIsNotOverlap(): void
    {
        // Existing entry: 08:00 – 12:00
        $entry = $this->makeEntry(
            id:      1,
            startAt: '2026-04-08 12:00:00',  // starts exactly when other ends
            endAt:   '2026-04-08 17:00:00',
            breakMinutes: 0,
            netMinutes:   300,
        );

        $ctx = $this->makeContext(otherEntries: [
            ['id' => 99, 'start_at' => '2026-04-08 08:00:00', 'end_at' => '2026-04-08 12:00:00'],
        ]);

        $flags = $this->engine->evaluate($entry, $ctx);
        $keys  = array_map(fn(Flag $f) => $f->flagKey, $flags);

        $this->assertNotContains('overlap', $keys);
    }

    public function testBoundaryStartEqualsEndIsNotOverlap(): void
    {
        // Existing entry: 13:00 – 17:00
        $entry = $this->makeEntry(
            id:      1,
            startAt: '2026-04-08 09:00:00',
            endAt:   '2026-04-08 13:00:00',   // ends exactly when other starts
            breakMinutes: 0,
            netMinutes:   240,
        );

        $ctx = $this->makeContext(otherEntries: [
            ['id' => 99, 'start_at' => '2026-04-08 13:00:00', 'end_at' => '2026-04-08 17:00:00'],
        ]);

        $flags = $this->engine->evaluate($entry, $ctx);
        $keys  = array_map(fn(Flag $f) => $f->flagKey, $flags);

        $this->assertNotContains('overlap', $keys);
    }

    // -------------------------------------------------------------------------
    // Overlap detection (strict)
    // -------------------------------------------------------------------------

    public function testTrueOverlapGeneratesOverlapFlag(): void
    {
        // Entry: 10:00 – 14:00 overlaps with existing 12:00 – 16:00
        $entry = $this->makeEntry(
            startAt: '2026-04-08 10:00:00',
            endAt:   '2026-04-08 14:00:00',
            breakMinutes: 0,
            netMinutes:   240,
        );

        $ctx = $this->makeContext(otherEntries: [
            ['id' => 5, 'start_at' => '2026-04-08 12:00:00', 'end_at' => '2026-04-08 16:00:00'],
        ]);

        $flags = $this->engine->evaluate($entry, $ctx);
        $keys  = array_map(fn(Flag $f) => $f->flagKey, $flags);

        $this->assertContains('overlap', $keys);
    }

    public function testNoOtherEntriesProducesNoOverlapFlag(): void
    {
        $entry = $this->makeEntry();
        $ctx   = $this->makeContext(otherEntries: []);

        $flags = $this->engine->evaluate($entry, $ctx);
        $keys  = array_map(fn(Flag $f) => $f->flagKey, $flags);

        $this->assertNotContains('overlap', $keys);
    }

    // -------------------------------------------------------------------------
    // Shift too long
    // -------------------------------------------------------------------------

    public function testShiftTooLongFlagIsEmitted(): void
    {
        $entry = $this->makeEntry(
            startAt:      '2026-04-08 08:00:00',
            endAt:        '2026-04-08 19:00:00',
            breakMinutes: 0,
            netMinutes:   660,
        );
        $ctx = $this->makeContext(maxShiftMinutes: 600);

        $flags = $this->engine->evaluate($entry, $ctx);
        $keys  = array_map(fn(Flag $f) => $f->flagKey, $flags);

        $this->assertContains('shift_too_long', $keys);
    }

    public function testShiftExactlyAtMaxDoesNotFlag(): void
    {
        $entry = $this->makeEntry(
            startAt:      '2026-04-08 08:00:00',
            endAt:        '2026-04-08 18:00:00',
            breakMinutes: 0,
            netMinutes:   600,
        );
        $ctx = $this->makeContext(maxShiftMinutes: 600);

        $flags = $this->engine->evaluate($entry, $ctx);
        $keys  = array_map(fn(Flag $f) => $f->flagKey, $flags);

        $this->assertNotContains('shift_too_long', $keys);
    }

    // -------------------------------------------------------------------------
    // Break too short (6 h rule)
    // -------------------------------------------------------------------------

    public function testBreakTooShortFlagFor6hShift(): void
    {
        // shift = 361 min, break = 0 (< 30 required)
        $entry = $this->makeEntry(
            startAt:      '2026-04-08 09:00:00',
            endAt:        '2026-04-08 15:01:00',
            breakMinutes: 0,
            netMinutes:   361,
        );
        $ctx = $this->makeContext(breakRequired6h: 30);

        $flags = $this->engine->evaluate($entry, $ctx);
        $keys  = array_map(fn(Flag $f) => $f->flagKey, $flags);

        $this->assertContains('break_too_short', $keys);
    }

    public function testNoBreakFlagWhenShiftIsShort(): void
    {
        // shift = 240 min (< 360), no break required
        $entry = $this->makeEntry(
            startAt:      '2026-04-08 09:00:00',
            endAt:        '2026-04-08 13:00:00',
            breakMinutes: 0,
            netMinutes:   240,
        );
        $ctx = $this->makeContext(breakRequired6h: 30);

        $flags = $this->engine->evaluate($entry, $ctx);
        $keys  = array_map(fn(Flag $f) => $f->flagKey, $flags);

        $this->assertNotContains('break_too_short', $keys);
    }

    // -------------------------------------------------------------------------
    // Flag ordering is alphabetical
    // -------------------------------------------------------------------------

    public function testFlagsAreSortedAlphabetically(): void
    {
        // Trigger both overlap and shift_too_long
        $entry = $this->makeEntry(
            startAt:      '2026-04-08 08:00:00',
            endAt:        '2026-04-08 19:00:00',
            breakMinutes: 0,
            netMinutes:   660,
        );
        $ctx = $this->makeContext(
            maxShiftMinutes: 600,
            otherEntries: [
                ['id' => 5, 'start_at' => '2026-04-08 10:00:00', 'end_at' => '2026-04-08 12:00:00'],
            ],
        );

        $flags = $this->engine->evaluate($entry, $ctx);
        $keys  = array_map(fn(Flag $f) => $f->flagKey, $flags);

        // Sorted alphabetically: overlap < shift_too_long
        $sorted = $keys;
        sort($sorted);
        $this->assertSame($sorted, $keys);
    }

    // -------------------------------------------------------------------------
    // Deduplication: first occurrence wins
    // -------------------------------------------------------------------------

    public function testDuplicateFlagKeysAreDeduplicatedKeepingFirst(): void
    {
        // break_too_short_9h rule also emits 'break_too_short' — dedup should keep first
        // shift > 540 min AND break < 6h-required AND break < 9h-required
        // Both rules B3 and B4 emit 'break_too_short' — only one should appear
        $entry = $this->makeEntry(
            startAt:      '2026-04-08 08:00:00',
            endAt:        '2026-04-08 17:30:00',
            breakMinutes: 0,
            netMinutes:   570,
        );
        $ctx = $this->makeContext(
            breakRequired6h: 30,
            breakRequired9h: 45,
        );

        $flags = $this->engine->evaluate($entry, $ctx);
        $keys  = array_map(fn(Flag $f) => $f->flagKey, $flags);

        // Only one 'break_too_short' entry
        $this->assertSame(1, count(array_filter($keys, fn(string $k) => $k === 'break_too_short')));
    }

    // -------------------------------------------------------------------------
    // Clean entry produces no flags
    // -------------------------------------------------------------------------

    public function testCleanEntryProducesNoFlags(): void
    {
        $entry = $this->makeEntry(
            startAt:      '2026-04-08 09:00:00',
            endAt:        '2026-04-08 17:00:00',
            breakMinutes: 30,
            netMinutes:   450,
        );
        $ctx = $this->makeContext(
            maxShiftMinutes: 600,
            breakRequired6h: 30,
            breakRequired9h: 45,
            otherEntries:    [],
        );

        $flags = $this->engine->evaluate($entry, $ctx);

        $this->assertSame([], $flags);
    }

    // =========================================================================
    // AN5.5 – Announcement missing / deviation
    // =========================================================================

    /**
     * AN5.5 must-have: no announcement on a weekend ⇒ announcement_missing flag.
     *
     * 2026-04-11 is a Saturday.
     */
    public function testAnnouncementMissingFlagOnWeekend(): void
    {
        $entry = $this->makeEntry(
            startAt:      '2026-04-11 09:00:00',  // Saturday
            endAt:        '2026-04-11 17:00:00',
            breakMinutes: 30,
            netMinutes:   450,
        );

        $ctx = $this->makeContext(
            approvedAnnouncements: [],  // no announcement
        );

        $flags = $this->engine->evaluate($entry, $ctx);
        $keys  = array_map(fn(Flag $f) => $f->flagKey, $flags);

        $this->assertContains('announcement_missing', $keys);
    }

    /**
     * AN5.5: no announcement_missing on a normal weekday within regular hours.
     *
     * 2026-04-08 is a Wednesday. Shift = 450 min < 480 default. No announcement required.
     */
    public function testNoAnnouncementMissingFlagOnWeekdayWithinRegularHours(): void
    {
        $entry = $this->makeEntry(
            startAt:      '2026-04-08 09:00:00',  // Wednesday
            endAt:        '2026-04-08 17:00:00',
            breakMinutes: 30,
            netMinutes:   450,
        );

        $ctx = $this->makeContext(
            maxDailyRegularMinutes: 480,
            approvedAnnouncements:  [],
        );

        $flags = $this->engine->evaluate($entry, $ctx);
        $keys  = array_map(fn(Flag $f) => $f->flagKey, $flags);

        $this->assertNotContains('announcement_missing', $keys);
    }

    /**
     * AN5.5: shift exceeds maxDailyRegularMinutes on a weekday ⇒ announcement_missing.
     *
     * 2026-04-07 is a Tuesday. Shift = 540 min > 480 default.
     */
    public function testAnnouncementMissingWhenShiftExceedsDailyRegularOnWeekday(): void
    {
        $entry = $this->makeEntry(
            startAt:      '2026-04-07 09:00:00',  // Tuesday
            endAt:        '2026-04-07 18:00:00',
            breakMinutes: 0,
            netMinutes:   540,
        );

        $ctx = $this->makeContext(
            maxDailyRegularMinutes: 480,
            approvedAnnouncements:  [],  // no announcement
        );

        $flags = $this->engine->evaluate($entry, $ctx);
        $keys  = array_map(fn(Flag $f) => $f->flagKey, $flags);

        $this->assertContains('announcement_missing', $keys);
    }

    /**
     * AN5.5 must-have: announcement present but deviation over threshold ⇒ announcement_deviation.
     *
     * Entry starts 09:00 Saturday; announcement planned_start 07:00 (120 min early).
     * Default maxDeviationMinutes = 30, so 120 > 30 ⇒ deviation flag.
     */
    public function testAnnouncementDeviationFlagWhenDeviationExceedsThreshold(): void
    {
        $entry = $this->makeEntry(
            startAt:      '2026-04-11 09:00:00',  // Saturday
            endAt:        '2026-04-11 17:00:00',
            breakMinutes: 30,
            netMinutes:   450,
        );

        $ctx = $this->makeContext(
            maxDeviationMinutes:   30,
            approvedAnnouncements: [
                [
                    'id'               => 1,
                    'planned_start_at' => '2026-04-11 07:00:00',  // 120 min earlier
                    'planned_end_at'   => '2026-04-11 15:00:00',
                ],
            ],
        );

        $flags = $this->engine->evaluate($entry, $ctx);
        $keys  = array_map(fn(Flag $f) => $f->flagKey, $flags);

        $this->assertContains('announcement_deviation', $keys);
        $this->assertNotContains('announcement_missing', $keys);
    }

    /**
     * AN5.5: announcement present and deviation within threshold ⇒ no deviation flag.
     *
     * Entry starts 09:00 Saturday; announcement planned_start 08:45 (15 min earlier).
     * Default maxDeviationMinutes = 30, so 15 <= 30 ⇒ no flag.
     */
    public function testNoAnnouncementDeviationFlagWhenWithinThreshold(): void
    {
        $entry = $this->makeEntry(
            startAt:      '2026-04-11 09:00:00',  // Saturday
            endAt:        '2026-04-11 17:00:00',
            breakMinutes: 30,
            netMinutes:   450,
        );

        $ctx = $this->makeContext(
            maxDeviationMinutes:   30,
            approvedAnnouncements: [
                [
                    'id'               => 1,
                    'planned_start_at' => '2026-04-11 08:45:00',  // 15 min earlier
                    'planned_end_at'   => '2026-04-11 16:45:00',
                ],
            ],
        );

        $flags = $this->engine->evaluate($entry, $ctx);
        $keys  = array_map(fn(Flag $f) => $f->flagKey, $flags);

        $this->assertNotContains('announcement_deviation', $keys);
        $this->assertNotContains('announcement_missing', $keys);
    }

    /**
     * AN5.5: selects the closest announcement (smallest abs deviation) when multiple exist.
     *
     * Entry starts 09:00 Saturday.
     * Ann A: planned_start 06:00 (180 min diff)
     * Ann B: planned_start 08:50 (10 min diff) ← closest
     * maxDeviationMinutes = 30 → only 10 min diff ⇒ no flag.
     */
    public function testBestCandidateSelectedFromMultipleAnnouncements(): void
    {
        $entry = $this->makeEntry(
            startAt:      '2026-04-11 09:00:00',  // Saturday
            endAt:        '2026-04-11 17:00:00',
            breakMinutes: 30,
            netMinutes:   450,
        );

        $ctx = $this->makeContext(
            maxDeviationMinutes:   30,
            approvedAnnouncements: [
                [
                    'id'               => 1,
                    'planned_start_at' => '2026-04-11 06:00:00',  // 180 min diff
                    'planned_end_at'   => '2026-04-11 14:00:00',
                ],
                [
                    'id'               => 2,
                    'planned_start_at' => '2026-04-11 08:50:00',  // 10 min diff
                    'planned_end_at'   => '2026-04-11 16:50:00',
                ],
            ],
        );

        $flags = $this->engine->evaluate($entry, $ctx);
        $keys  = array_map(fn(Flag $f) => $f->flagKey, $flags);

        $this->assertNotContains('announcement_deviation', $keys);
        $this->assertNotContains('announcement_missing', $keys);
    }
}
