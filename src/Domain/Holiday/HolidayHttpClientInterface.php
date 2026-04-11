<?php

declare(strict_types=1);

namespace App\Domain\Holiday;

interface HolidayHttpClientInterface
{
    /**
     * Fetch holidays for a given German state and year from the remote API.
     *
      * URL pattern: {baseUrl}?years={year}&states={state}
      * Optional: {baseUrl}?years={year}&all_states=1
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws HolidaySyncException on timeout, HTTP 5xx, or invalid JSON
     */
    public function fetchYear(string $baseUrl, string $state, int $year, int $timeoutSeconds): array;
}
