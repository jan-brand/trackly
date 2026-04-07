<?php

declare(strict_types=1);

namespace App\Support;

class Env
{
    private static bool $loaded = false;

    public static function load(string $path): void
    {
        if (!file_exists($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES);

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if ($trimmed === '') {
                continue;
            }

            if (str_starts_with($trimmed, '#')) {
                continue;
            }

            if (!str_contains($trimmed, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $trimmed, 2);

            if (str_starts_with($value, '"') && str_ends_with($value, '"') && strlen($value) >= 2) {
                $value = substr($value, 1, -1);
            }

            $_ENV[$key] = $value;
        }

        self::$loaded = true;
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        return $_ENV[$key] ?? $default;
    }
}
