<?php

declare(strict_types=1);

namespace App\Support;

final class MailSender
{
    public function send(string $to, string $subject, string $body): void
    {
        // In CLI/test runs we keep the message in-memory so assertions can
        // inspect it without depending on a configured mail transport.
        $transport = strtolower((string) ($_ENV['MAIL_TRANSPORT'] ?? (getenv('MAIL_TRANSPORT') ?: 'log')));

        if (PHP_SAPI === 'cli' || $transport === 'log') {
            EmailQueue::record($to, $subject, $body);
            return;
        }

        $from = $_ENV['ADMIN_EMAIL'] ?? (getenv('ADMIN_EMAIL') ?: 'no-reply@localhost');
        $headers = implode("\r\n", [
            'From: ' . $from,
            'Content-Type: text/plain; charset=UTF-8',
        ]);

        // Avoid noisy PHP warnings when SMTP/sendmail is not configured.
        $previousHandler = set_error_handler(static function (): bool {
            return true;
        });

        try {
            $sent = @mail($to, $subject, $body, $headers);
        } finally {
            restore_error_handler();
            if ($previousHandler !== null) {
                set_error_handler($previousHandler);
            }
        }

        if (!$sent) {
            // Fallback: keep mail content available instead of breaking the flow.
            EmailQueue::record($to, $subject, $body);
            error_log('[MailSender] mail() failed; message was queued in-memory.');
            return;
        }

        EmailQueue::record($to, $subject, $body);
    }
}
