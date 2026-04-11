<?php

declare(strict_types=1);

namespace App\Domain\Holiday;

interface HolidayHttpClientInterface
{
    /**
     * Fetch holidays for a given year from the remote API.
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws HolidaySyncException on timeout, HTTP 5xx, or invalid JSON
     */
    public function fetchYear(string $baseUrl, int $year, int $timeoutSeconds): array;
}
