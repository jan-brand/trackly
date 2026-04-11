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
     * Fetches holidays for the current and next year from the configured API
     * and upserts them into the DB.  On any HTTP error (timeout, 5xx, invalid
     * JSON) no DB writes are performed and a flash error is shown.
     */
    public function sync(): Response
    {
        Csrf::verifyOrFail();
        Guard::requireRole(['admin']);

        $baseUrl        = (string) Env::get('HOLIDAYS_API_BASE_URL', '');
        $timeoutSeconds = (int) Env::get('HOLIDAYS_API_TIMEOUT_SECONDS', '15');

        $currentYear = (int) date('Y');
        $years       = [$currentYear, $currentYear + 1];

        $allRows = [];
        $client  = $this->getHttpClient();

        foreach ($years as $year) {
            try {
                $rows    = $client->fetchYear($baseUrl, $year, $timeoutSeconds);
                $allRows = array_merge($allRows, $rows);
            } catch (HolidaySyncException $e) {
                Flash::addError('Feiertage-Sync fehlgeschlagen: ' . $e->getMessage());
                return new Response(303, ['Location' => '/admin/settings'], '');
            }
        }

        $repo = new HolidayRepository(Db::pdo());
        $repo->upsertMany($allRows);

        Flash::addSuccess('Feiertage erfolgreich synchronisiert.');

        return new Response(303, ['Location' => '/admin/settings'], '');
    }
}
