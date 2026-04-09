<?php

declare(strict_types=1);

namespace App\Domain\Time;

/**
 * Contract for rule engine implementations.
 */
interface RuleEngineInterface
{
    /**
     * Evaluate all rules and return the sorted, deduplicated flag list.
     *
     * @param  TimeEntry   $entry  The entry to evaluate
     * @param  RuleContext $ctx    Settings and sibling entries for context
     * @return list<Flag>
     */
    public function evaluate(TimeEntry $entry, RuleContext $ctx): array;
}
