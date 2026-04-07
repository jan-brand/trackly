<?php

declare(strict_types=1);

namespace App\Db;

use PDO;
use RuntimeException;

class SeedRunner
{
    public function __construct(private readonly PDO $pdo) {}

    /**
     * Run all seeds in the given directory in lexicographic order.
     *
     * Each seed file must return a callable with signature:
     *   function(PDO $pdo, bool $resetAdminPassword = false): void
     *
     * @return int Number of seeds executed.
     *
     * @throws RuntimeException when a seed file does not return a callable or execution fails.
     */
    public function run(string $seedsDir, bool $resetAdminPassword = false): int
    {
        $files = glob($seedsDir . '/*.php');

        if ($files === false) {
            throw new RuntimeException("Could not read seeds directory: {$seedsDir}");
        }

        sort($files);

        $count = 0;

        foreach ($files as $file) {
            $name = basename($file, '.php');
            $seed = require $file;

            if (!is_callable($seed)) {
                throw new RuntimeException("Seed {$name} does not return a callable.");
            }

            try {
                $seed($this->pdo, $resetAdminPassword);
            } catch (\Throwable $e) {
                throw new RuntimeException("Seed {$name} failed: " . $e->getMessage(), 0, $e);
            }

            $count++;
        }

        return $count;
    }
}
