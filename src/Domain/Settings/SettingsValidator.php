<?php

declare(strict_types=1);

namespace App\Domain\Settings;

/**
 * Validates and normalises a settings payload against the registry whitelist.
 *
 * Usage:
 *   $result = (new SettingsValidator())->validateSettingsPayload($input);
 *   if (!$result->isValid()) { /* return HTTP 422 with $result->errors *\/ }
 */
final class SettingsValidator
{
    private SettingsRegistry $registry;

    public function __construct(?SettingsRegistry $registry = null)
    {
        $this->registry = $registry ?? new SettingsRegistry();
    }

    /**
     * Validate and normalise a flat associative array of setting values.
     *
     * @param  array<string, mixed> $input  Raw input (e.g. from $_POST)
     * @return ValidationResult
     */
    public function validateSettingsPayload(array $input): ValidationResult
    {
        /** @var array<string, list<string>> $errors */
        $errors = [];
        /** @var array<string, mixed> $values */
        $values = [];

        // ------------------------------------------------------------------
        // 1. Reject unknown keys
        // ------------------------------------------------------------------
        foreach ($input as $key => $_) {
            if (!$this->registry->has($key)) {
                $errors[$key][] = "Unknown setting key \"{$key}\".";
            }
        }

        // ------------------------------------------------------------------
        // 2. Validate and normalise each known key present in the input.
        //    Missing keys fall back to their registered defaults.
        // ------------------------------------------------------------------
        foreach ($this->registry->all() as $def) {
            $raw = $input[$def->key] ?? null;

            if ($raw === null) {
                $values[$def->key] = $def->default;
                continue;
            }

            [$normalised, $fieldErrors] = $this->validateField($def, $raw);

            if ($fieldErrors !== []) {
                $errors[$def->key] = array_merge($errors[$def->key] ?? [], $fieldErrors);
            } else {
                $values[$def->key] = $normalised;
            }
        }

        // ------------------------------------------------------------------
        // 3. Cross-field constraints (only when all relevant fields are valid)
        // ------------------------------------------------------------------
        $global = $this->checkCrossFieldConstraints($values, $errors);
        if ($global !== []) {
            $errors['_global'] = array_merge($errors['_global'] ?? [], $global);
        }

        return new ValidationResult($values, $errors);
    }

    // -----------------------------------------------------------------------
    // Private helpers
    // -----------------------------------------------------------------------

    /**
     * Validate a single field and return [normalisedValue, errors].
     *
     * @return array{0: mixed, 1: list<string>}
     */
    private function validateField(SettingDefinition $def, mixed $raw): array
    {
        $errors = [];

        switch ($def->type) {
            case 'bool':
                if (!in_array($raw, [true, false, 1, 0, '1', '0', 'true', 'false'], true)) {
                    $errors[] = "Field \"{$def->key}\" must be a boolean value.";
                    return [null, $errors];
                }
                return [in_array($raw, [true, 1, '1', 'true'], true), $errors];

            case 'int':
                if (!is_numeric($raw)) {
                    $errors[] = "Field \"{$def->key}\" must be an integer.";
                    return [null, $errors];
                }
                $value = (int) $raw;
                if ($def->min !== null && $value < $def->min) {
                    $errors[] = "Field \"{$def->key}\" must be at least {$def->min}.";
                }
                if ($def->max !== null && $value > $def->max) {
                    $errors[] = "Field \"{$def->key}\" must be at most {$def->max}.";
                }
                return $errors === [] ? [$value, []] : [null, $errors];

            case 'string':
                if (!is_string($raw)) {
                    $errors[] = "Field \"{$def->key}\" must be a string.";
                    return [null, $errors];
                }
                if ($def->regex !== null && !preg_match($def->regex, $raw)) {
                    $errors[] = "Field \"{$def->key}\" has an invalid format.";
                    return [null, $errors];
                }
                return [$raw, []];

            case 'enum':
                if (!is_string($raw)) {
                    $errors[] = "Field \"{$def->key}\" must be a string.";
                    return [null, $errors];
                }
                if ($def->enumOptions !== null && !in_array($raw, $def->enumOptions, true)) {
                    $allowed = implode(', ', $def->enumOptions);
                    $errors[] = "Field \"{$def->key}\" must be one of: {$allowed}.";
                    return [null, $errors];
                }
                return [$raw, []];

            default:
                $errors[] = "Field \"{$def->key}\" has an unsupported type \"{$def->type}\".";
                return [null, $errors];
        }
    }

    /**
     * Check cross-field constraints against the already-normalised values.
     * Only evaluated when both sides of a constraint are present and valid.
     *
     * @param  array<string, mixed>          $values
     * @param  array<string, list<string>>   $errors  Already-collected per-field errors
     * @return list<string>
     */
    private function checkCrossFieldConstraints(array $values, array $errors): array
    {
        $global = [];

        // Helper: returns true when a key has a per-field error (making its
        // normalised value unreliable) or is absent from $values entirely.
        $hasFieldError = static fn(string $k): bool => isset($errors[$k]);

        // ----------------------------------------------------------------
        // adult.max_daily_exception_minutes >= adult.max_daily_regular_minutes
        // ----------------------------------------------------------------
        $a = 'adult.max_daily_exception_minutes';
        $b = 'adult.max_daily_regular_minutes';
        if (!$hasFieldError($a) && !$hasFieldError($b)) {
            if (($values[$a] ?? 0) < ($values[$b] ?? 0)) {
                $global[] = "adult.max_daily_exception_minutes must be greater than or equal to adult.max_daily_regular_minutes.";
            }
        }

        // ----------------------------------------------------------------
        // adult.max_weekly_exception_minutes >= adult.max_weekly_regular_minutes
        // ----------------------------------------------------------------
        $a = 'adult.max_weekly_exception_minutes';
        $b = 'adult.max_weekly_regular_minutes';
        if (!$hasFieldError($a) && !$hasFieldError($b)) {
            if (($values[$a] ?? 0) < ($values[$b] ?? 0)) {
                $global[] = "adult.max_weekly_exception_minutes must be greater than or equal to adult.max_weekly_regular_minutes.";
            }
        }

        // ----------------------------------------------------------------
        // adult.break_required_over_9h_minutes >= adult.break_required_over_6h_minutes
        // ----------------------------------------------------------------
        $a = 'adult.break_required_over_9h_minutes';
        $b = 'adult.break_required_over_6h_minutes';
        if (!$hasFieldError($a) && !$hasFieldError($b)) {
            if (($values[$a] ?? 0) < ($values[$b] ?? 0)) {
                $global[] = "adult.break_required_over_9h_minutes must be greater than or equal to adult.break_required_over_6h_minutes.";
            }
        }

        // ----------------------------------------------------------------
        // youth.allowed_start_time < youth.allowed_end_time <= youth.allowed_end_time_exception
        // ----------------------------------------------------------------
        $start     = 'youth.allowed_start_time';
        $end       = 'youth.allowed_end_time';
        $endExcept = 'youth.allowed_end_time_exception';
        if (!$hasFieldError($start) && !$hasFieldError($end) && !$hasFieldError($endExcept)) {
            $vs = $values[$start]     ?? '';
            $ve = $values[$end]       ?? '';
            $vx = $values[$endExcept] ?? '';
            if ($vs !== '' && $ve !== '' && $vx !== '') {
                if ($vs >= $ve) {
                    $global[] = "youth.allowed_start_time must be earlier than youth.allowed_end_time.";
                }
                if ($ve > $vx) {
                    $global[] = "youth.allowed_end_time must be earlier than or equal to youth.allowed_end_time_exception.";
                }
            }
        }

        return $global;
    }
}
