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
                label:   'Tägliche Regelarbeitszeit',
                uiType:  'duration',
                min:     0,
                max:     1440,
            ),
            new SettingDefinition(
                key:     'adult.max_daily_exception_minutes',
                type:    'int',
                default: 600,
                label:   'Tägliche Ausnahmearbeitszeit',
                uiType:  'duration',
                min:     0,
                max:     1440,
            ),
            new SettingDefinition(
                key:     'adult.max_weekly_regular_minutes',
                type:    'int',
                default: 2400,
                label:   'Wöchentliche Regelarbeitszeit',
                uiType:  'duration',
                min:     0,
                max:     10080,
            ),
            new SettingDefinition(
                key:     'adult.max_weekly_exception_minutes',
                type:    'int',
                default: 2700,
                label:   'Wöchentliche Ausnahmearbeitszeit',
                uiType:  'duration',
                min:     0,
                max:     10080,
            ),
            new SettingDefinition(
                key:     'adult.break_required_over_6h_minutes',
                type:    'int',
                default: 30,
                label:   'Mindestpause ab 6 Stunden (Minuten)',
                min:     0,
                max:     120,
            ),
            new SettingDefinition(
                key:     'adult.break_required_over_9h_minutes',
                type:    'int',
                default: 45,
                label:   'Mindestpause ab 9 Stunden (Minuten)',
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
                label:   'Früheste Arbeitszeit (Jugendliche)',
                uiType:  'time',
                regex:   '/^\d{2}:\d{2}$/',
            ),
            new SettingDefinition(
                key:     'youth.allowed_end_time',
                type:    'string',
                default: '20:00',
                label:   'Späteste Arbeitszeit (Jugendliche)',
                uiType:  'time',
                regex:   '/^\d{2}:\d{2}$/',
            ),
            new SettingDefinition(
                key:     'youth.allowed_end_time_exception',
                type:    'string',
                default: '22:00',
                label:   'Späteste Arbeitszeit – Ausnahme (Jugendliche)',
                uiType:  'time',
                regex:   '/^\d{2}:\d{2}$/',
            ),
            // ----------------------------------------------------------------
            // Tracking behaviour (bool examples)
            // ----------------------------------------------------------------
            new SettingDefinition(
                key:     'tracking.require_break_confirmation',
                type:    'bool',
                default: false,
                label:   'Pausenbestätigung erforderlich',
            ),
            new SettingDefinition(
                key:     'tracking.allow_retroactive_entries',
                type:    'bool',
                default: true,
                label:   'Nachträgliche Einträge erlauben',
            ),
            // ----------------------------------------------------------------
            // Youth work category (enum example)
            // ----------------------------------------------------------------
            new SettingDefinition(
                key:          'youth.work_category',
                type:         'enum',
                default:      'standard',
                label:        'Arbeitskategorie (Jugendliche)',
                enumOptions:  ['light', 'standard', 'heavy'],
                enumLabels:   ['Leicht', 'Standard', 'Schwer'],
            ),
            // ----------------------------------------------------------------
            // Application / timezone
            // ----------------------------------------------------------------
            new SettingDefinition(
                key:     'app.timezone',
                type:    'string',
                default: 'Europe/Berlin',
                label:   'Zeitzone',
            ),
            // ----------------------------------------------------------------
            // Work rules for manual time entry
            // ----------------------------------------------------------------
            new SettingDefinition(
                key:     'work.allow_overnight_shifts',
                type:    'bool',
                default: false,
                label:   'Nachtschichten erlauben',
            ),
            new SettingDefinition(
                key:     'work.max_shift_minutes',
                type:    'int',
                default: 600,
                label:   'Maximale Schichtdauer (Minuten)',
                min:     1,
                max:     1440,
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
