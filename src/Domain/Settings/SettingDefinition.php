<?php

declare(strict_types=1);

namespace App\Domain\Settings;

/**
 * Immutable value object that describes a single whitelisted setting.
 */
final class SettingDefinition
{
    /**
     * @param  string                    $key          Dot-separated key, e.g. "adult.max_daily_regular_minutes"
     * @param  string                    $type         One of: bool | int | string | enum
     * @param  mixed                     $default      Default value (already of the correct PHP type)
     * @param  int|null                  $min          Minimum value (int type only)
     * @param  int|null                  $max          Maximum value (int type only)
     * @param  string|null               $regex        Validation regex (string type only, must include delimiters)
     * @param  list<string>|null         $enumOptions  Allowed values (enum type only)
     */
    public function __construct(
        public readonly string  $key,
        public readonly string  $type,
        public readonly mixed   $default,
        public readonly ?int    $min         = null,
        public readonly ?int    $max         = null,
        public readonly ?string $regex       = null,
        public readonly ?array  $enumOptions = null,
    ) {}
}
