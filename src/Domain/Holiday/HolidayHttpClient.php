<?php

declare(strict_types=1);

namespace App\Domain\Holiday;

/**
 * Real HTTP client that fetches holidays from a remote JSON API via cURL.
 *
 * URL pattern: {baseUrl}/{year}
 *
 * Expected response: JSON array of objects with keys
 *   date_local, state, name, is_public_holiday.
 */
class HolidayHttpClient implements HolidayHttpClientInterface
{
    public function fetchYear(string $baseUrl, int $year, int $timeoutSeconds): array
    {
        $url = rtrim($baseUrl, '/') . '/' . $year;

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeoutSeconds);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

        $body   = curl_exec($ch);
        $code   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errNo  = curl_errno($ch);
        curl_close($ch);

        if ($errNo !== 0 || $body === false) {
            throw new HolidaySyncException(
                "HTTP request failed (errno: {$errNo})."
            );
        }

        if ($code >= 500) {
            throw new HolidaySyncException("Server error: HTTP {$code}.");
        }

        $data = json_decode((string) $body, true);
        if (!is_array($data)) {
            throw new HolidaySyncException('Invalid JSON response from holidays API.');
        }

        return $data;
    }
}
