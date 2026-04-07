#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Db\Db;
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
$files = glob($seedsDir . '/*.php');

if ($files === false) {
    fwrite(STDERR, "Could not read seeds directory." . PHP_EOL);
    exit(1);
}

sort($files);

foreach ($files as $file) {
    $name = basename($file, '.php');
    $seed = require $file;

    if (!is_callable($seed)) {
        fwrite(STDERR, "Seed {$name} does not return a callable." . PHP_EOL);
        exit(1);
    }

    try {
        $seed($pdo, $resetAdminPassword);
        echo "OK    {$name}" . PHP_EOL;
    } catch (\Throwable $e) {
        fwrite(STDERR, "FAIL  {$name}: " . $e->getMessage() . PHP_EOL);
        exit(1);
    }
}

echo "Done." . PHP_EOL;
exit(0);
