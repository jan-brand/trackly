<?php

declare(strict_types=1);

namespace App\Support;

class Flash
{
    private const SESSION_KEY = '__flash';

    public static function addSuccess(string $msg): void
    {
        self::init();
        $_SESSION[self::SESSION_KEY]['success'][] = $msg;
    }

    public static function addError(string $msg): void
    {
        self::init();
        $_SESSION[self::SESSION_KEY]['error'][] = $msg;
    }

    /**
     * @return array{success: array<string>, error: array<string>}
     */
    public static function consume(): array
    {
        self::init();
        $messages = $_SESSION[self::SESSION_KEY];
        $_SESSION[self::SESSION_KEY] = ['success' => [], 'error' => []];
        return $messages;
    }

    private static function init(): void
    {
        if (!isset($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = ['success' => [], 'error' => []];
        }
    }
}
