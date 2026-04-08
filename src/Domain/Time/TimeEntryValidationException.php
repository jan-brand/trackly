<?php

declare(strict_types=1);

namespace App\Domain\Time;

/**
 * Thrown when time entry input fails validation.
 *
 * Maps to HTTP 422 at the controller layer.
 */
final class TimeEntryValidationException extends \RuntimeException
{
    /**
     * @param array<string, list<string>> $errors  Per-field and global error messages
     */
    public function __construct(
        public readonly array $errors,
        string $message = 'Time entry validation failed.',
    ) {
        parent::__construct($message);
    }
}
