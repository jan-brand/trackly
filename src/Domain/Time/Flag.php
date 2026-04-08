<?php

declare(strict_types=1);

namespace App\Domain\Time;

/**
 * Immutable flag produced by the Rule Engine for a time entry.
 */
final class Flag
{
    /**
     * @param string      $flagKey    Unique identifier for the rule/flag (e.g. 'overlap', 'shift_too_long')
     * @param string|null $flagValue  Optional metadata value persisted alongside the key
     */
    public function __construct(
        public readonly string $flagKey,
        public readonly ?string $flagValue = null,
    ) {}
}
