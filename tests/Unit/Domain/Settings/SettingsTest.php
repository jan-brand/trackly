<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Settings;

use App\Domain\Settings\Settings;
use App\Domain\Settings\SettingsRegistry;
use App\Domain\Settings\UnknownSettingKeyException;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Settings read-model.
 *
 * Uses an in-memory SQLite database so no real MariaDB is required.
 * SQLite supports JSON columns as plain TEXT, which is sufficient here
 * because JSON parsing is handled in PHP (json_decode).
 */
class SettingsTest extends TestCase
{
    private PDO             $pdo;
    private SettingsRegistry $registry;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $this->pdo->exec("
            CREATE TABLE settings (
                key                 TEXT    NOT NULL,
                value_json          TEXT    NOT NULL,
                updated_by_user_id  INTEGER NOT NULL,
                updated_at          TEXT    NOT NULL,
                PRIMARY KEY (key)
            )
        ");

        $this->registry = new SettingsRegistry();
    }

    // -------------------------------------------------------------------------
    // Default fallback
    // -------------------------------------------------------------------------

    public function testMissingDbRowReturnsRegistryDefault(): void
    {
        // No rows inserted – every key must come from the registry default.
        $settings = new Settings($this->pdo, $this->registry);

        $this->assertSame(480, $settings->get('adult.max_daily_regular_minutes'));
        $this->assertSame('06:00', $settings->get('youth.allowed_start_time'));
    }

    public function testAllReturnsAllKeysWhenTableIsEmpty(): void
    {
        $settings = new Settings($this->pdo, $this->registry);
        $all = $settings->all();

        foreach ($this->registry->all() as $def) {
            $this->assertArrayHasKey($def->key, $all);
            $this->assertSame($def->default, $all[$def->key]);
        }
    }

    // -------------------------------------------------------------------------
    // DB value overrides default
    // -------------------------------------------------------------------------

    public function testDbValueOverridesDefault(): void
    {
        $this->pdo->exec(
            "INSERT INTO settings (key, value_json, updated_by_user_id, updated_at)
             VALUES ('adult.max_daily_regular_minutes', '360', 1, '2026-01-01 00:00:00')"
        );

        $settings = new Settings($this->pdo, $this->registry);

        $this->assertSame(360, $settings->get('adult.max_daily_regular_minutes'));
    }

    public function testMixedDbAndDefaultValues(): void
    {
        $this->pdo->exec(
            "INSERT INTO settings (key, value_json, updated_by_user_id, updated_at)
             VALUES ('youth.allowed_start_time', '\"07:00\"', 1, '2026-01-01 00:00:00')"
        );

        $settings = new Settings($this->pdo, $this->registry);

        // Overridden by DB.
        $this->assertSame('07:00', $settings->get('youth.allowed_start_time'));
        // Others still default.
        $this->assertSame('20:00', $settings->get('youth.allowed_end_time'));
    }

    // -------------------------------------------------------------------------
    // Request-scoped cache
    // -------------------------------------------------------------------------

    public function testDbIsQueriedOnlyOnce(): void
    {
        // We use a query-counting PDO wrapper to confirm the DB is hit once.
        $queryCount = 0;
        $pdo = new class ('sqlite::memory:') extends PDO {
            public int $queries = 0;

            public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): \PDOStatement|false
            {
                $this->queries++;
                if ($fetchMode !== null) {
                    return parent::query($query, $fetchMode, ...$fetchModeArgs);
                }
                return parent::query($query);
            }
        };
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec("
            CREATE TABLE settings (
                key                 TEXT    NOT NULL,
                value_json          TEXT    NOT NULL,
                updated_by_user_id  INTEGER NOT NULL,
                updated_at          TEXT    NOT NULL,
                PRIMARY KEY (key)
            )
        ");

        $settings = new Settings($pdo, $this->registry);

        // First call loads from DB.
        $settings->all();
        $this->assertSame(1, $pdo->queries);

        // Second call must use the in-memory cache – no additional DB query.
        $settings->all();
        $this->assertSame(1, $pdo->queries);

        // get() also goes through the same cache.
        $settings->get('adult.max_daily_regular_minutes');
        $this->assertSame(1, $pdo->queries);
    }

    // -------------------------------------------------------------------------
    // Missing table – graceful degradation
    // -------------------------------------------------------------------------

    public function testAllReturnsDefaultsWhenSettingsTableDoesNotExist(): void
    {
        // Build a fresh in-memory SQLite that intentionally has no settings table.
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $settings = new Settings($pdo, $this->registry);
        $all      = $settings->all();

        foreach ($this->registry->all() as $def) {
            $this->assertArrayHasKey($def->key, $all, "Key {$def->key} must be present even without DB table");
            $this->assertSame($def->default, $all[$def->key], "Key {$def->key} must equal registry default");
        }
    }

    // -------------------------------------------------------------------------
    // Unknown key
    // -------------------------------------------------------------------------

    public function testGetThrowsForUnknownKey(): void
    {
        $this->expectException(UnknownSettingKeyException::class);

        $settings = new Settings($this->pdo, $this->registry);
        $settings->get('does.not.exist');
    }
}
