#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Db\Db;
use App\Db\SeedRunner;
use App\Support\Env;

require_once dirname(__DIR__) . '/vendor/autoload.php';

Env::load(dirname(__DIR__) . '/.env');

$resetAdminPassword = in_array('--reset-admin-password', $argv ?? [], true);

try {
    $pdo = Db::pdo();
} catch (\Throwable $e) {
    fwrite(STDERR, "DB connection failed: " . $e->getMessage() . PHP_EOL);
    exit(1);
}

$seedsDir = dirname(__DIR__) . '/db/seeds';

try {
    $runner = new SeedRunner($pdo);
    $count  = $runner->run($seedsDir, $resetAdminPassword);
} catch (\Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}

echo "Done. Seeds executed: {$count}" . PHP_EOL;
exit(0);
