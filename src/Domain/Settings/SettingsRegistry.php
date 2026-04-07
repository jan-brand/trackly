<?php

declare(strict_types=1);

namespace App\Domain\Settings;

/**
 * Central whitelist of every setting that the application accepts.
 *
 * Add new settings here; the validator automatically rejects any key
 * that is not listed in this registry.
 */
final class SettingsRegistry
{
    /** @var array<string, SettingDefinition> */
    private array $definitions = [];

    public function __construct()
    {
        $this->register(
            // ----------------------------------------------------------------
            // Adult work-time limits
            // ----------------------------------------------------------------
            new SettingDefinition(
                key:     'adult.max_daily_regular_minutes',
                type:    'int',
                default: 480,
                min:     0,
                max:     1440,
            ),
            new SettingDefinition(
                key:     'adult.max_daily_exception_minutes',
                type:    'int',
                default: 600,
                min:     0,
                max:     1440,
            ),
            new SettingDefinition(
                key:     'adult.max_weekly_regular_minutes',
                type:    'int',
                default: 2400,
                min:     0,
                max:     10080,
            ),
            new SettingDefinition(
                key:     'adult.max_weekly_exception_minutes',
                type:    'int',
                default: 2700,
                min:     0,
                max:     10080,
            ),
            new SettingDefinition(
                key:     'adult.break_required_over_6h_minutes',
                type:    'int',
                default: 30,
                min:     0,
                max:     120,
            ),
            new SettingDefinition(
                key:     'adult.break_required_over_9h_minutes',
                type:    'int',
                default: 45,
                min:     0,
                max:     120,
            ),
            // ----------------------------------------------------------------
            // Youth work-time restrictions
            // ----------------------------------------------------------------
            new SettingDefinition(
                key:     'youth.allowed_start_time',
                type:    'string',
                default: '06:00',
                regex:   '/^\d{2}:\d{2}$/',
            ),
            new SettingDefinition(
                key:     'youth.allowed_end_time',
                type:    'string',
                default: '20:00',
                regex:   '/^\d{2}:\d{2}$/',
            ),
            new SettingDefinition(
                key:     'youth.allowed_end_time_exception',
                type:    'string',
                default: '22:00',
                regex:   '/^\d{2}:\d{2}$/',
            ),
        );
    }

    private function register(SettingDefinition ...$defs): void
    {
        foreach ($defs as $def) {
            $this->definitions[$def->key] = $def;
        }
    }

    /** @return array<string, SettingDefinition> */
    public function all(): array
    {
        return $this->definitions;
    }

    public function has(string $key): bool
    {
        return isset($this->definitions[$key]);
    }

    public function get(string $key): SettingDefinition
    {
        if (!$this->has($key)) {
            throw new \InvalidArgumentException("Unknown setting key: {$key}");
        }
        return $this->definitions[$key];
    }
}
