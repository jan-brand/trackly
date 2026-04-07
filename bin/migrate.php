#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Db\Db;
use App\Support\Env;

require_once dirname(__DIR__) . '/vendor/autoload.php';

Env::load(dirname(__DIR__) . '/.env');

try {
    $pdo = Db::pdo();
} catch (\Throwable $e) {
    fwrite(STDERR, "DB connection failed: " . $e->getMessage() . PHP_EOL);
    exit(1);
}

$pdo->exec("
    CREATE TABLE IF NOT EXISTS schema_migrations (
        migration_key VARCHAR(255) NOT NULL,
        applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (migration_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

$migrationsDir = dirname(__DIR__) . '/db/migrations';
$files = glob($migrationsDir . '/*.php');

if ($files === false) {
    fwrite(STDERR, "Could not read migrations directory." . PHP_EOL);
    exit(1);
}

sort($files);

$checkStmt = $pdo->prepare('SELECT 1 FROM schema_migrations WHERE migration_key = :key');
$insertStmt = $pdo->prepare(
    'INSERT INTO schema_migrations (migration_key, applied_at) VALUES (:key, NOW())'
);

$applied = 0;
$skipped = 0;

foreach ($files as $file) {
    $key = basename($file, '.php');

    $checkStmt->execute([':key' => $key]);
    if ($checkStmt->fetchColumn() !== false) {
        echo "SKIP  {$key}" . PHP_EOL;
        $skipped++;
        continue;
    }

    $migration = require $file;

    if (!is_callable($migration)) {
        fwrite(STDERR, "Migration {$key} does not return a callable." . PHP_EOL);
        exit(1);
    }

    try {
        $pdo->beginTransaction();
        $migration($pdo);
        $insertStmt->execute([':key' => $key]);
        $pdo->commit();
        echo "OK    {$key}" . PHP_EOL;
        $applied++;
    } catch (\Throwable $e) {
        $pdo->rollBack();
        fwrite(STDERR, "FAIL  {$key}: " . $e->getMessage() . PHP_EOL);
        exit(1);
    }
}

echo "Done. Applied: {$applied}, Skipped: {$skipped}" . PHP_EOL;
exit(0);
