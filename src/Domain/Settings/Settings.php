<?php

declare(strict_types=1);

namespace App\Domain\Settings;

use PDO;

/**
 * Read-model for application settings.
 *
 * Values are loaded from the `settings` DB table at most once per instance
 * (i.e. once per request when constructed in the bootstrap).  If a key is
 * not present in the DB the default from SettingsRegistry is returned.
 *
 * Throws UnknownSettingKeyException for any key not registered in the
 * SettingsRegistry whitelist.
 */
final class Settings
{
    /** @var array<string, mixed>|null  null = not yet loaded */
    private ?array $cache = null;

    public function __construct(
        private readonly PDO             $pdo,
        private readonly SettingsRegistry $registry,
    ) {}

    /**
     * Get the value for a single setting key.
     *
     * @throws UnknownSettingKeyException
     */
    public function get(string $key): mixed
    {
        if (!$this->registry->has($key)) {
            throw new UnknownSettingKeyException($key);
        }

        $all = $this->all();

        return $all[$key];
    }

    /**
     * Return all settings as a flat associative array.
     * Missing DB rows are filled in with registry defaults.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        // Start from the full set of defaults so missing rows are covered.
        $values = [];
        foreach ($this->registry->all() as $def) {
            $values[$def->key] = $def->default;
        }

        // Overwrite with whatever is stored in the DB.
        // Guard against the settings table not yet existing (pending migration).
        try {
            $stmt = $this->pdo->query('SELECT `key`, `value_json` FROM settings');
            if ($stmt !== false) {
                foreach ($stmt->fetchAll() as $row) {
                    $key = $row['key'];
                    if ($this->registry->has($key)) {
                        $decoded = json_decode((string) $row['value_json'], true, 512, JSON_THROW_ON_ERROR);
                        $values[$key] = $decoded;
                    }
                }
            }
        } catch (\PDOException) {
            // Table not yet created (migration pending); fall back to registry defaults.
        }

        $this->cache = $values;

        return $this->cache;
    }
}
