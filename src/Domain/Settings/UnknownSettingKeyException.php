<?php

declare(strict_types=1);

namespace App\Domain\Settings;

/**
 * Thrown when a caller requests a setting key that is not in the registry.
 */
final class UnknownSettingKeyException extends \InvalidArgumentException
{
    public function __construct(string $key)
    {
        parent::__construct("Unknown setting key: \"{$key}\".");
    }
}
