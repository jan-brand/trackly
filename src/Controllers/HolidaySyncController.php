<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Db\Db;
use App\Domain\Holiday\HolidayHttpClient;
use App\Domain\Holiday\HolidayHttpClientInterface;
use App\Domain\Holiday\HolidayRepository;
use App\Domain\Holiday\HolidaySyncException;
use App\Http\Response;
use App\Security\Csrf;
use App\Security\Guard;
use App\Support\Env;
use App\Support\Flash;

class HolidaySyncController
{
    /** Valid German federal state codes (ISO 3166-2:DE). */
    private const VALID_STATES = [
        'BB', 'BE', 'BW', 'BY', 'HB', 'HE', 'HH',
        'MV', 'NI', 'NW', 'RP', 'SH', 'SL', 'SN', 'ST', 'TH',
    ];

    /** Overrideable HTTP client for tests. */
    private static ?HolidayHttpClientInterface $httpClient = null;

    public static function setHttpClient(?HolidayHttpClientInterface $client): void
    {
        self::$httpClient = $client;
    }

    private function getHttpClient(): HolidayHttpClientInterface
    {
        return self::$httpClient ?? new HolidayHttpClient();
    }

    /**
     * POST /admin/holidays/sync
     *
     * Fetches holidays for the given German state and year from the configured
     * API and upserts them into the DB.  On any HTTP error (timeout, 5xx,
     * invalid JSON) no DB writes are performed and a flash error is shown.
     */
    public function sync(): Response
    {
        Csrf::verifyOrFail();
        Guard::requireRole(['admin']);

        $state = strtoupper(trim((string) ($_POST['state'] ?? '')));
        $year  = (int) ($_POST['year'] ?? 0);

        // ---- Validate state ----
        if (!in_array($state, self::VALID_STATES, true)) {
            Flash::addError('Ungültiges Bundesland.');
            return new Response(303, ['Location' => '/admin/settings'], '');
        }

        // ---- Validate year: current-1 … current+4 ----
        $currentYear = (int) date('Y');
        if ($year < $currentYear - 1 || $year > $currentYear + 4) {
            Flash::addError('Ungültiges Jahr. Erlaubt: ' . ($currentYear - 1) . '–' . ($currentYear + 4) . '.');
            return new Response(303, ['Location' => '/admin/settings'], '');
        }

        $baseUrl        = (string) Env::get('HOLIDAYS_API_BASE_URL', '');
        $timeoutSeconds = (int) Env::get('HOLIDAYS_API_TIMEOUT_SECONDS', '15');

        try {
            $rows = $this->getHttpClient()->fetchYear($baseUrl, $state, $year, $timeoutSeconds);
        } catch (HolidaySyncException $e) {
            Flash::addError('Feiertage-Sync fehlgeschlagen: ' . $e->getMessage());
            return new Response(303, ['Location' => '/admin/settings'], '');
        }

        $repo = new HolidayRepository(Db::pdo());
        $repo->upsertMany($rows);

        Flash::addSuccess(
            sprintf('Feiertage für %s / %d erfolgreich synchronisiert.', $state, $year)
        );

        return new Response(303, ['Location' => '/admin/settings'], '');
    }
}
