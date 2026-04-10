<?php

declare(strict_types=1);

namespace App\Domain\Announcement;

/**
 * Thrown when announcement input fails validation.
 *
 * Maps to HTTP 422 at the controller layer.
 */
final class AnnouncementValidationException extends \RuntimeException
{
    /**
     * @param array<string, list<string>> $errors  Per-field and global error messages
     */
    public function __construct(
        public readonly array $errors,
        string $message = 'Announcement validation failed.',
    ) {
        parent::__construct($message);
    }
}
