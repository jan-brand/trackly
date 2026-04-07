<?php

declare(strict_types=1);

namespace App\Domain\Settings;

/**
 * Holds the outcome of a settings validation run.
 *
 * Error structure (SSR-compatible):
 *   $errors['fieldKey']  = ['message 1', ...]   (per-field errors)
 *   $errors['_global']   = ['message 1', ...]   (cross-field / global errors)
 */
final class ValidationResult
{
    /**
     * @param  array<string, mixed>      $values  Normalised, type-safe values for every known key
     * @param  array<string, list<string>> $errors  Field and global error messages
     */
    public function __construct(
        public readonly array $values,
        public readonly array $errors,
    ) {}

    public function isValid(): bool
    {
        return $this->errors === [];
    }
}
