<?php

declare(strict_types=1);

namespace App\Tests\Feature\Admin;

use App\Db\Db;
use App\Domain\Settings\SettingsRegistry;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Feature tests for POST /admin/settings (S1.4).
 *
 * Uses an in-memory SQLite database injected via reflection so no real
 * MariaDB connection is required.
 */
class AdminSettingsPostTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $_SESSION = [];
        $_POST    = [];
        $_SERVER['REQUEST_METHOD'] = 'POST';

        $this->pdo = $this->buildSqlitePdo();
        $this->injectPdo($this->pdo);
    }

    protected function tearDown(): void
    {
        $this->resetDbInstance();

        $_SESSION = [];
        $_POST    = [];
    }

    // -------------------------------------------------------------------------
    // S1.4a: CSRF error → 403
    // -------------------------------------------------------------------------

    public function testCsrfViolationReturns403(): void
    {
        // Set a valid token in session but send a *wrong* token in POST body.
        $_SESSION['__csrf_token'] = bin2hex(random_bytes(16));

        $result = simulateRequest(
            'POST',
            '/admin/settings',
            array_merge(
                $this->buildPayload('wrong-token', 'Valid reason here'),
                ['csrf_token' => 'wrong-token'],
            ),
            [],
            [
                'user_id'      => 1,
                '__user_roles' => ['admin'],
                '__csrf_token' => bin2hex(random_bytes(16)), // different from POST
            ],
        );

        $this->assertSame(403, $result['status']);
    }

    // -------------------------------------------------------------------------
    // S1.4a: RBAC error (non-admin) → 403
    // -------------------------------------------------------------------------

    public function testNonAdminReturns403(): void
    {
        $csrfToken = $this->setCsrfToken();

        $result = simulateRequest(
            'POST',
            '/admin/settings',
            $this->buildPayload($csrfToken, 'Valid reason here'),
            [],
            [
                'user_id'      => 2,
                '__user_roles' => ['employee'],
                '__csrf_token' => $csrfToken,
            ],
        );

        $this->assertSame(403, $result['status']);
    }

    // -------------------------------------------------------------------------
    // Must-have: 1 save → exactly 1 audit row
    // -------------------------------------------------------------------------

    public function testValidSaveCreatesExactlyOneAuditRow(): void
    {
        $csrfToken = $this->setCsrfToken();

        $result = simulateRequest(
            'POST',
            '/admin/settings',
            $this->buildPayload($csrfToken, 'Gespeichert wegen Test'),
            [],
            [
                'user_id'         => 1,
                '__user_roles'    => ['admin'],
                '__csrf_token'    => $csrfToken,
            ],
        );

        // Should redirect on success.
        $this->assertSame(303, $result['status']);
        $this->assertSame('/admin/settings', $result['headers']['Location']);

        // Exactly one audit row must exist.
        $count = (int) $this->pdo
            ->query('SELECT COUNT(*) FROM settings_audit_log')
            ->fetchColumn();

        $this->assertSame(1, $count);
    }

    // -------------------------------------------------------------------------
    // S1.4a: success flash message exact text
    // -------------------------------------------------------------------------

    public function testSuccessFlashMessageIsExact(): void
    {
        $csrfToken = $this->setCsrfToken();

        $result = dispatch(
            'POST',
            '/admin/settings',
            $this->buildPayload($csrfToken, 'Begründung für Test'),
            [
                'user_id'      => 1,
                '__user_roles' => ['admin'],
                '__csrf_token' => $csrfToken,
            ],
        );

        $this->assertSame(303, $result['status']);

        $flash = $result['session']['__flash'] ?? [];
        $this->assertContains(
            'Einstellungen gespeichert.',
            $flash['success'] ?? [],
            'Flash success message must be exactly "Einstellungen gespeichert."',
        );
    }

    public function testHolidayDefaultStateIsPersisted(): void
    {
        $csrfToken = $this->setCsrfToken();
        $payload   = $this->buildPayload($csrfToken, 'Default-Bundesland angepasst');
        $payload['settings']['holiday.default_state'] = 'TH';

        $result = simulateRequest(
            'POST',
            '/admin/settings',
            $payload,
            [],
            [
                'user_id'      => 1,
                '__user_roles' => ['admin'],
                '__csrf_token' => $csrfToken,
            ],
        );

        $this->assertSame(303, $result['status']);

        $stmt = $this->pdo->prepare('SELECT value_json FROM settings WHERE `key` = :key');
        $stmt->execute([':key' => 'holiday.default_state']);
        $valueJson = $stmt->fetchColumn();

        $this->assertNotFalse($valueJson);
        $this->assertSame('TH', json_decode((string) $valueJson, true));
    }

    /**
     * Two saves must produce two independent audit rows.
     */
    public function testTwoSavesCreateTwoAuditRows(): void
    {
        foreach (['Erste Änderung', 'Zweite Änderung'] as $reason) {
            $csrfToken = $this->setCsrfToken();

            simulateRequest(
                'POST',
                '/admin/settings',
                $this->buildPayload($csrfToken, $reason),
                [],
                [
                    'user_id'      => 1,
                    '__user_roles' => ['admin'],
                    '__csrf_token' => $csrfToken,
                ],
            );
        }

        $count = (int) $this->pdo
            ->query('SELECT COUNT(*) FROM settings_audit_log')
            ->fetchColumn();

        $this->assertSame(2, $count);
    }

    // -------------------------------------------------------------------------
    // Must-have: invalid payload → 0 writes
    // -------------------------------------------------------------------------

    /**
     * A missing / too-short reason must result in 0 settings rows and
     * 0 audit rows.
     */
    public function testMissingReasonWritesNothing(): void
    {
        $csrfToken = $this->setCsrfToken();

        $result = simulateRequest(
            'POST',
            '/admin/settings',
            $this->buildPayload($csrfToken, 'ab'), // reason < 3 chars
            [],
            [
                'user_id'      => 1,
                '__user_roles' => ['admin'],
                '__csrf_token' => $csrfToken,
            ],
        );

        $this->assertSame(422, $result['status']);

        $settingsCount = (int) $this->pdo
            ->query('SELECT COUNT(*) FROM settings')
            ->fetchColumn();

        $auditCount = (int) $this->pdo
            ->query('SELECT COUNT(*) FROM settings_audit_log')
            ->fetchColumn();

        $this->assertSame(0, $settingsCount, 'No settings row must be written on validation failure.');
        $this->assertSame(0, $auditCount, 'No audit row must be written on validation failure.');
    }

    /**
     * An unknown / non-whitelisted settings key must also be rejected with
     * 0 writes.
     */
    public function testUnknownSettingsKeyWritesNothing(): void
    {
        $csrfToken = $this->setCsrfToken();

        $result = simulateRequest(
            'POST',
            '/admin/settings',
            array_merge(
                $this->buildPayload($csrfToken, 'Valid reason'),
                ['settings' => ['unknown.key.xyz' => '999']],
            ),
            [],
            [
                'user_id'      => 1,
                '__user_roles' => ['admin'],
                '__csrf_token' => $csrfToken,
            ],
        );

        $this->assertSame(422, $result['status']);

        $auditCount = (int) $this->pdo
            ->query('SELECT COUNT(*) FROM settings_audit_log')
            ->fetchColumn();

        $this->assertSame(0, $auditCount);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Build a valid POST payload with all registry keys set to their defaults.
     *
     * @return array<string, mixed>
     */
    private function buildPayload(string $csrfToken, string $reason): array
    {
        $registry = new SettingsRegistry();
        $settings = [];
        foreach ($registry->all() as $def) {
            if ($def->type === 'bool') {
                $settings[$def->key] = $def->default ? '1' : '0';
            } elseif ($def->uiType === 'duration') {
                $mins = (int) $def->default;
                $settings[$def->key] = sprintf('%02d:%02d', intdiv($mins, 60), $mins % 60);
            } else {
                $settings[$def->key] = (string) $def->default;
            }
        }

        return [
            'csrf_token' => $csrfToken,
            'reason'     => $reason,
            'settings'   => $settings,
        ];
    }

    private function setCsrfToken(): string
    {
        $token = bin2hex(random_bytes(16));
        $_SESSION['__csrf_token'] = $token;
        return $token;
    }

    /**
     * Build an in-memory SQLite PDO with the settings and audit-log schema.
     */
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
            CREATE TABLE settings_audit_log (
                id               INTEGER PRIMARY KEY AUTOINCREMENT,
                actor_user_id    INTEGER NOT NULL,
                action           TEXT    NOT NULL,
                reason           TEXT    NOT NULL,
                old_value_json   TEXT    NULL,
                new_value_json   TEXT    NOT NULL,
                created_at       TEXT    NOT NULL
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
