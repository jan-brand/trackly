<?php

declare(strict_types=1);

namespace App\Tests\Feature\Admin;

use App\Db\Db;
use App\Domain\Settings\SettingsRegistry;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * S1.5 – Atomicity and audit-semantics tests for POST /admin/settings.
 *
 * Uses an in-memory SQLite database injected via reflection; no real
 * MariaDB connection is required.
 */
class AdminSettingsAtomicityTest extends TestCase
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
    // S1.5 – Test 1: atomicity
    // Two settings keys in payload, one with an out-of-range (invalid) value.
    // Expected: HTTP 422, settings table unchanged, no audit row.
    // -------------------------------------------------------------------------

    public function testAtomicity_InvalidValueLeavesDbUntouched(): void
    {
        $csrfToken = $this->setCsrfToken();

        // Build a base-valid payload first…
        $payload = $this->buildValidPayload($csrfToken, 'Valid reason');

        // …then corrupt one value: max is 1440, so 9999 is out of range.
        $payload['settings']['adult.max_daily_regular_minutes'] = '9999';

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

        $this->assertSame(422, $result['status'], 'Invalid value must yield HTTP 422');

        $settingsCount = (int) $this->pdo
            ->query('SELECT COUNT(*) FROM settings')
            ->fetchColumn();

        $auditCount = (int) $this->pdo
            ->query('SELECT COUNT(*) FROM settings_audit_log')
            ->fetchColumn();

        $this->assertSame(0, $settingsCount, 'No settings rows must be written on validation failure');
        $this->assertSame(0, $auditCount,    'No audit rows must be written on validation failure');
    }

    // -------------------------------------------------------------------------
    // S1.5 – Test 2: audit snapshot
    // Valid payload → exactly 1 audit row with correct old/new snapshots.
    // -------------------------------------------------------------------------

    public function testAuditSnapshot_ContainsOldAndNewValues(): void
    {
        $csrfToken = $this->setCsrfToken();

        // Change one known-default value so we can verify the delta.
        // Registry default is 480 min (08:00); we submit 500 min (08:20).
        $payload = $this->buildValidPayload($csrfToken, 'Audit snapshot test');
        $payload['settings']['adult.max_daily_regular_minutes'] = '08:20';

        // The exception limit must remain >= the regular limit to pass
        // cross-field validation; default is 600 which is already ≥ 500.

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

        $this->assertSame(303, $result['status'], 'Valid payload must yield HTTP 303');

        $row = $this->pdo
            ->query('SELECT old_value_json, new_value_json FROM settings_audit_log')
            ->fetch(PDO::FETCH_ASSOC);

        $this->assertIsArray($row, 'Exactly one audit row must exist');

        $old = json_decode((string) $row['old_value_json'], true);
        $new = json_decode((string) $row['new_value_json'], true);

        // old_value_json must reflect the registry default (480 min) because the
        // DB was empty before this save.
        $this->assertSame(
            480,
            $old['adult.max_daily_regular_minutes'],
            'old_value_json must contain the pre-save default value',
        );

        // new_value_json must reflect what was written (500 min).
        $this->assertSame(
            500,
            $new['adult.max_daily_regular_minutes'],
            'new_value_json must contain the submitted value',
        );
    }

    // -------------------------------------------------------------------------
    // S1.5 – Test 3: unknown key
    // Payload contains an unknown key → HTTP 422, no writes.
    // -------------------------------------------------------------------------

    public function testUnknownKey_RejectsWithNoWrites(): void
    {
        $csrfToken = $this->setCsrfToken();

        $result = simulateRequest(
            'POST',
            '/admin/settings',
            [
                'csrf_token' => $csrfToken,
                'reason'     => 'Valid reason here',
                'settings'   => ['totally.unknown.key' => 'value'],
            ],
            [],
            [
                'user_id'      => 1,
                '__user_roles' => ['admin'],
                '__csrf_token' => $csrfToken,
            ],
        );

        $this->assertSame(422, $result['status'], 'Unknown key must yield HTTP 422');

        $auditCount = (int) $this->pdo
            ->query('SELECT COUNT(*) FROM settings_audit_log')
            ->fetchColumn();

        $settingsCount = (int) $this->pdo
            ->query('SELECT COUNT(*) FROM settings')
            ->fetchColumn();

        $this->assertSame(0, $settingsCount, 'No settings rows must be written for unknown key');
        $this->assertSame(0, $auditCount,    'No audit rows must be written for unknown key');
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Build a completely valid POST payload from registry defaults.
     *
     * @return array<string, mixed>
     */
    private function buildValidPayload(string $csrfToken, string $reason): array
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
