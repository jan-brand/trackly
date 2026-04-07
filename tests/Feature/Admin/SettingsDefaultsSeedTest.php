<?php

declare(strict_types=1);

namespace App\Tests\Feature\Admin;

use App\Db\Db;
use App\Domain\Settings\SettingsRegistry;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * S1.6 – Tests for the settings-defaults seed (0005_settings_defaults.php).
 */
class SettingsDefaultsSeedTest extends TestCase
{
    private PDO $pdo;

    /** @var callable */
    private $seed;

    protected function setUp(): void
    {
        $this->pdo  = $this->buildSqlitePdo();
        $this->seed = require dirname(__DIR__, 3) . '/db/seeds/0005_settings_defaults.php';
    }

    // -------------------------------------------------------------------------
    // S1.6 – Test: all registry keys are inserted with defaults on first run
    // -------------------------------------------------------------------------

    public function testSeedInsertsAllRegistryKeysWithDefaults(): void
    {
        ($this->seed)($this->pdo);

        $registry = new SettingsRegistry();
        $expected = count($registry->all());

        $actual = (int) $this->pdo
            ->query('SELECT COUNT(*) FROM settings')
            ->fetchColumn();

        $this->assertSame($expected, $actual, 'Every registry key must have a row after the seed');

        // Verify one known default (adult.max_daily_regular_minutes = 480)
        $stmt = $this->pdo->prepare('SELECT value_json FROM settings WHERE `key` = :key');
        $stmt->execute([':key' => 'adult.max_daily_regular_minutes']);
        $valueJson = $stmt->fetchColumn();

        $this->assertNotFalse($valueJson, 'Key adult.max_daily_regular_minutes must be present');
        $this->assertSame(480, json_decode((string) $valueJson, true));
    }

    // -------------------------------------------------------------------------
    // S1.6 – Test: idempotency (running seed twice → same row count, unchanged values)
    // -------------------------------------------------------------------------

    public function testSeedIsIdempotent(): void
    {
        // First run
        ($this->seed)($this->pdo);

        $countAfterFirst = (int) $this->pdo
            ->query('SELECT COUNT(*) FROM settings')
            ->fetchColumn();

        // Manually change one value to verify it is NOT overwritten on second run
        $this->pdo->exec(
            "UPDATE settings SET value_json = '999' WHERE `key` = 'adult.max_daily_regular_minutes'"
        );

        // Second run
        ($this->seed)($this->pdo);

        $countAfterSecond = (int) $this->pdo
            ->query('SELECT COUNT(*) FROM settings')
            ->fetchColumn();

        $this->assertSame(
            $countAfterFirst,
            $countAfterSecond,
            'Row count must be identical after running the seed a second time',
        );

        // The manually-changed value must still be 999 (seed must not overwrite)
        $stmt = $this->pdo->prepare('SELECT value_json FROM settings WHERE `key` = :key');
        $stmt->execute([':key' => 'adult.max_daily_regular_minutes']);
        $valueJson = $stmt->fetchColumn();

        $this->assertSame(
            999,
            json_decode((string) $valueJson, true),
            'Pre-existing row must not be overwritten by a second seed run',
        );
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function buildSqlitePdo(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        // Minimal tables required by the seed
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
            CREATE TABLE roles (
                id  INTEGER PRIMARY KEY AUTOINCREMENT,
                key TEXT    NOT NULL UNIQUE
            )
        ");

        $pdo->exec("
            CREATE TABLE user_roles (
                user_id INTEGER NOT NULL,
                role_id INTEGER NOT NULL,
                PRIMARY KEY (user_id, role_id)
            )
        ");

        // Insert a minimal admin role + user so updated_by_user_id resolves
        $pdo->exec("INSERT INTO roles (key) VALUES ('admin')");
        $pdo->exec("INSERT INTO user_roles (user_id, role_id) VALUES (1, 1)");

        return $pdo;
    }
}
