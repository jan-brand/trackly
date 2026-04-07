#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Db\Db;
use App\Db\MigrationRunner;
use App\Support\Env;

require_once dirname(__DIR__) . '/vendor/autoload.php';

Env::load(dirname(__DIR__) . '/.env');

try {
    $pdo = Db::pdo();
} catch (\Throwable $e) {
    fwrite(STDERR, "DB connection failed: " . $e->getMessage() . PHP_EOL);
    exit(1);
}

$migrationsDir = dirname(__DIR__) . '/db/migrations';

try {
    $runner = new MigrationRunner($pdo);
    $result = $runner->run($migrationsDir);
} catch (\Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}

echo "Done. Applied: {$result['applied']}, Skipped: {$result['skipped']}" . PHP_EOL;
exit(0);
