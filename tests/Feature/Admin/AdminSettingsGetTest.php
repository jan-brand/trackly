<?php

declare(strict_types=1);

namespace App\Tests\Feature\Admin;

use App\Db\Db;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Feature tests for GET /admin/settings.
 */
class AdminSettingsGetTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $_SESSION = [];
        $_POST    = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $this->pdo = $this->buildSqlitePdo();
        $this->injectPdo($this->pdo);
    }

    protected function tearDown(): void
    {
        $this->resetDbInstance();

        $_SESSION = [];
        $_POST    = [];
    }

    /**
     * An admin must receive HTTP 200 and the settings form when accessing the settings screen.
     */
    public function testAdminGetSettingsReturns200(): void
    {
        $result = simulateRequest(
            'GET',
            '/admin/settings',
            [],
            [],
            [
                'user_id'      => 1,
                '__user_roles' => ['admin'],
            ],
        );

        $this->assertSame(200, $result['status']);
        $this->assertStringContainsString('Einstellungen', $result['body']);
    }

    /**
     * An employee (non-admin) must receive HTTP 403 when accessing the settings screen.
     */
    public function testEmployeeGetSettingsReturns403(): void
    {
        $result = simulateRequest(
            'GET',
            '/admin/settings',
            [],
            [],
            [
                'user_id'      => 42,
                '__user_roles' => ['employee'],
            ],
        );

        $this->assertSame(403, $result['status']);
    }

    /**
     * A guest (not logged in) must also receive HTTP 403.
     */
    public function testGuestGetSettingsReturns403(): void
    {
        $result = simulateRequest(
            'GET',
            '/admin/settings',
            [],
            [],
            [], // no session → not logged in
        );

        $this->assertSame(403, $result['status']);
    }

    /**
     * A coordination role (non-admin) must also receive HTTP 403, because the
     * settings screen is restricted to admin only.
     */
    public function testCoordinationGetSettingsReturns403(): void
    {
        $result = simulateRequest(
            'GET',
            '/admin/settings',
            [],
            [],
            [
                'user_id'      => 99,
                '__user_roles' => ['coordination'],
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

        $pdo->exec("
            CREATE TABLE settings (
                key                  TEXT    NOT NULL,
                value_json           TEXT    NOT NULL,
                label                TEXT    NOT NULL DEFAULT '',
                ui_type              TEXT    NOT NULL DEFAULT '',
                updated_by_user_id   INTEGER NOT NULL,
                updated_at           TEXT    NOT NULL,
                PRIMARY KEY (key)
            )
        ");

        $pdo->exec("
            CREATE TABLE holidays (
                date_local        TEXT    NOT NULL,
                state             TEXT    NOT NULL,
                name              TEXT    NOT NULL,
                is_public_holiday INTEGER NOT NULL DEFAULT 0,
                PRIMARY KEY (date_local, state)
            )
        ");

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
}
