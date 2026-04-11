<?php

declare(strict_types=1);

namespace App\Support;

final class EmailQueue
{
    /**
     * @var list<array{to: string, subject: string, body: string}>
     */
    private static array $messages = [];

    public static function record(string $to, string $subject, string $body): void
    {
        self::$messages[] = [
            'to' => $to,
            'subject' => $subject,
            'body' => $body,
        ];
    }

    /**
     * @return list<array{to: string, subject: string, body: string}>
     */
    public static function all(): array
    {
        return self::$messages;
    }

    public static function clear(): void
    {
        self::$messages = [];
    }
}
