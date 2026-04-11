<?php

declare(strict_types=1);

namespace App\Tests\Feature\Admin;

use App\Controllers\HolidaySyncController;
use App\Db\Db;
use App\Domain\Holiday\HolidayHttpClientInterface;
use App\Domain\Holiday\HolidaySyncException;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * H7.5 – Feature tests for POST /admin/holidays/sync.
 *
 * Uses an in-memory SQLite database injected via reflection.
 * The real HTTP client is replaced with anonymous-class mocks.
 */
class HolidaySyncTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = $this->buildSqlitePdo();
        $this->injectPdo($this->pdo);

        $_SESSION = [];
        $_POST    = [];
    }

    protected function tearDown(): void
    {
        HolidaySyncController::setHttpClient(null);
        $this->resetDbInstance();

        $_SESSION = [];
        $_POST    = [];
    }

    // -------------------------------------------------------------------------
    // H7.5 – Timeout ⇒ 0 Writes
    // -------------------------------------------------------------------------

    public function testTimeoutProducesZeroWrites(): void
    {
        // Mock that always throws a timeout-like exception
        HolidaySyncController::setHttpClient(
            new class implements HolidayHttpClientInterface {
                public function fetchYear(string $baseUrl, int $year, int $timeoutSeconds): array
                {
                    throw new HolidaySyncException('Connection timed out.');
                }
            }
        );

        $countBefore = (int) $this->pdo->query('SELECT COUNT(*) FROM holidays')->fetchColumn();

        $csrfToken = $this->setCsrfToken();
        simulateRequest(
            'POST',
            '/admin/holidays/sync',
            ['csrf_token' => $csrfToken],
            [],
            [
                'user_id'      => 1,
                '__user_roles' => ['admin'],
                '__csrf_token' => $csrfToken,
            ],
        );

        $countAfter = (int) $this->pdo->query('SELECT COUNT(*) FROM holidays')->fetchColumn();

        $this->assertSame(
            $countBefore,
            $countAfter,
            'A timeout must produce zero DB writes.',
        );
    }

    // -------------------------------------------------------------------------
    // H7.5 – Idempotency: 2 syncs ⇒ count exactly 2 (no duplicates)
    // -------------------------------------------------------------------------

    public function testTwoSyncsProduceNoDuplicates(): void
    {
        // Mock returns year-specific holidays so the total across both years is 2 unique rows.
        HolidaySyncController::setHttpClient(
            new class implements HolidayHttpClientInterface {
                public function fetchYear(string $baseUrl, int $year, int $timeoutSeconds): array
                {
                    return [
                        [
                            'date_local'        => $year . '-01-01',
                            'state'             => 'BE',
                            'name'              => 'Neujahr',
                            'is_public_holiday' => 1,
                        ],
                    ];
                }
            }
        );

        for ($i = 0; $i < 2; $i++) {
            $csrfToken = $this->setCsrfToken();
            simulateRequest(
                'POST',
                '/admin/holidays/sync',
                ['csrf_token' => $csrfToken],
                [],
                [
                    'user_id'      => 1,
                    '__user_roles' => ['admin'],
                    '__csrf_token' => $csrfToken,
                ],
            );
        }

        $count = (int) $this->pdo->query('SELECT COUNT(*) FROM holidays')->fetchColumn();

        // current year + next year = exactly 2 unique rows (no duplicates after 2 syncs)
        $this->assertSame(2, $count, 'Two syncs must produce exactly 2 unique rows.');
    }

    // -------------------------------------------------------------------------
    // H7.5 – RBAC: employee ⇒ 403
    // -------------------------------------------------------------------------

    public function testEmployeeCannotSync(): void
    {
        $csrfToken = $this->setCsrfToken();

        $result = simulateRequest(
            'POST',
            '/admin/holidays/sync',
            ['csrf_token' => $csrfToken],
            [],
            [
                'user_id'      => 1,
                '__user_roles' => ['employee'],
                '__csrf_token' => $csrfToken,
            ],
        );

        $this->assertSame(403, $result['status']);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function buildSqlitePdo(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $pdo->exec(
            'CREATE TABLE holidays (
                date_local        TEXT    NOT NULL,
                state             TEXT    NOT NULL,
                name              TEXT    NOT NULL,
                is_public_holiday INTEGER NOT NULL DEFAULT 0,
                PRIMARY KEY (date_local, state)
            )'
        );

        return $pdo;
    }

    private function injectPdo(PDO $pdo): void
    {
        $ref = new ReflectionProperty(Db::class, 'instance');
        $ref->setAccessible(true);
        $ref->setValue(null, $pdo);
    }

    private function resetDbInstance(): void
    {
        $ref = new ReflectionProperty(Db::class, 'instance');
        $ref->setAccessible(true);
        $ref->setValue(null, null);
    }

    private function setCsrfToken(): string
    {
        $token = bin2hex(random_bytes(16));
        $_SESSION['__csrf_token'] = $token;
        return $token;
    }
}
