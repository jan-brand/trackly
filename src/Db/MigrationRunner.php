<?php

declare(strict_types=1);

namespace App\Db;

use PDO;
use RuntimeException;

class MigrationRunner
{
    public function __construct(private readonly PDO $pdo) {}

    /**
     * Ensure the tracking table exists, run all pending migrations.
     *
     * @return array{applied: int, skipped: int}
     *
     * @throws RuntimeException when a migration file does not return a callable.
     */
    public function run(string $migrationsDir): array
    {
        $this->pdo->exec('
            CREATE TABLE IF NOT EXISTS schema_migrations (
                migration_key VARCHAR(255) NOT NULL,
                applied_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (migration_key)
            )
        ');

        $files = glob($migrationsDir . '/*.php');

        if ($files === false) {
            throw new RuntimeException("Could not read migrations directory: {$migrationsDir}");
        }

        sort($files);

        $checkStmt  = $this->pdo->prepare('SELECT 1 FROM schema_migrations WHERE migration_key = :key');
        $insertStmt = $this->pdo->prepare(
            'INSERT INTO schema_migrations (migration_key, applied_at) VALUES (:key, CURRENT_TIMESTAMP)'
        );

        // If there are no entries in schema_migrations the database is "fresh".
        // In many MySQL setups DDL statements cause an implicit commit which
        // makes per-migration transactions unreliable. For a fresh DB we
        // therefore skip wrapping each migration in its own transaction so
        // all migrations can be applied in one run without transaction errors.
        $fresh = $this->pdo->query('SELECT 1 FROM schema_migrations LIMIT 1')->fetchColumn() === false;

        $applied = 0;
        $skipped = 0;

        foreach ($files as $file) {
            $key = basename($file, '.php');

            $checkStmt->execute([':key' => $key]);
            if ($checkStmt->fetchColumn() !== false) {
                $skipped++;
                continue;
            }

            $migration = require $file;

            if (!is_callable($migration)) {
                throw new RuntimeException("Migration {$key} does not return a callable.");
            }

            // Only attempt a transaction when this is not a fresh DB. On a
            // fresh DB many migrations contain DDL which may implicitly
            // commit and break the transaction. Skipping transactions on a
            // fresh DB avoids "There is no active transaction" errors.
            if (!$fresh) {
                $this->pdo->beginTransaction();
            }

            try {
                $migration($this->pdo);
                $insertStmt->execute([':key' => $key]);

                // Commit only if a transaction is actually active.
                if ($this->pdo->inTransaction()) {
                    $this->pdo->commit();
                }
            } catch (\Throwable $e) {
                // Roll back only if a transaction is active.
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }

                throw new RuntimeException("Migration {$key} failed: " . $e->getMessage(), 0, $e);
            }

            $applied++;
        }

        return ['applied' => $applied, 'skipped' => $skipped];
    }
}
