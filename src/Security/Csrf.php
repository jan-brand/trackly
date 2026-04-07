<?php

declare(strict_types=1);

namespace App\Security;

use Exception;

class CsrfViolationException extends Exception {}

class Csrf
{
    private const SESSION_KEY = '__csrf_token';

    public static function token(): string
    {
        if (!isset($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(32));
        }

        return $_SESSION[self::SESSION_KEY];
    }

    public static function inputHtml(): string
    {
        $token = htmlspecialchars(self::token(), ENT_QUOTES, 'UTF-8');
        return '<input type="hidden" name="csrf_token" value="' . $token . '">';
    }

    public static function verifyOrFail(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return;
        }

        $sessionToken = $_SESSION[self::SESSION_KEY] ?? null;
        $postToken = $_POST['csrf_token'] ?? null;

        if ($postToken === null || $sessionToken === null || !hash_equals($sessionToken, $postToken)) {
            throw new CsrfViolationException('CSRF token mismatch.');
        }
    }
}
