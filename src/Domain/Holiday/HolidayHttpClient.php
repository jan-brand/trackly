<?php

declare(strict_types=1);

namespace App\Domain\Holiday;

/**
 * Real HTTP client that fetches holidays from a remote JSON API via cURL.
 *
 * URL pattern: {baseUrl}?years={year}&states={state}
 * Optional: {baseUrl}?years={year}&all_states=1
 *
 * Expected response (supported):
 * - Wrapped payload: {"status":"success","feiertage":[{"date":"...","fname":"..."}]}
 * - Direct row array: [{"date_local":"...","state":"...","name":"...","is_public_holiday":1}]
 */
class HolidayHttpClient implements HolidayHttpClientInterface
{
    public function fetchYear(string $baseUrl, string $state, int $year, int $timeoutSeconds): array
    {
        $normalizedBaseUrl = rtrim($baseUrl, '/');
        if ($normalizedBaseUrl === '') {
            throw new HolidaySyncException('HOLIDAYS_API_BASE_URL ist nicht gesetzt.');
        }

        $allStatesRaw      = (string) \App\Support\Env::get('HOLIDAYS_API_ALL_STATES', '0');
        $allStatesEnabled  = in_array(strtolower(trim($allStatesRaw)), ['1', 'true'], true);

        $query = ['years' => (string) $year];
        if ($allStatesEnabled) {
            $query['all_states'] = '1';
        } else {
            $query['states'] = strtolower(trim($state));
        }

        $url = $normalizedBaseUrl . '?' . http_build_query($query);

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

        // API can either return a wrapped payload (`feiertage`) or direct rows.
        $items = [];
        if (isset($data['feiertage']) && is_array($data['feiertage'])) {
            $items = $data['feiertage'];
        } elseif (array_is_list($data)) {
            $items = $data;
        } else {
            throw new HolidaySyncException('Unexpected holidays API payload shape.');
        }

        $normalizedRows = [];
        $stateUpper     = strtoupper(trim($state));
        $stateLower     = strtolower($stateUpper);

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            // Pass through already-normalized rows from compatible APIs.
            if (isset($item['date_local'], $item['state'], $item['name'])) {
                $normalizedRows[] = [
                    'date_local'        => (string) $item['date_local'],
                    'state'             => strtoupper((string) $item['state']),
                    'name'              => (string) $item['name'],
                    'is_public_holiday' => (int) ($item['is_public_holiday'] ?? 1),
                ];
                continue;
            }

            // Map get.api-feiertage.de entries (`date`, `fname`, state flags).
            if (!isset($item['date'], $item['fname'])) {
                continue;
            }

            $isPublicHoliday = 1;
            if (isset($item[$stateLower])) {
                $isPublicHoliday = (string) $item[$stateLower] === '1' ? 1 : 0;
            } elseif (isset($item['all_states'])) {
                $isPublicHoliday = (string) $item['all_states'] === '1' ? 1 : 0;
            }

            if ($isPublicHoliday !== 1) {
                continue;
            }

            $normalizedRows[] = [
                'date_local'        => (string) $item['date'],
                'state'             => $stateUpper,
                'name'              => (string) $item['fname'],
                'is_public_holiday' => 1,
            ];
        }

        return $normalizedRows;
    }
}
