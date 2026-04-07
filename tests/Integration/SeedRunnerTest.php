<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Db\SeedRunner;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class SeedRunnerTest extends TestCase
{
    private PDO $pdo;
    private string $seedsDir;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $this->seedsDir = sys_get_temp_dir() . '/trackly_seeds_' . uniqid();
        mkdir($this->seedsDir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->seedsDir . '/*') ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        rmdir($this->seedsDir);
    }

    public function testSeedsRunInLexicographicOrder(): void
    {
        $log = $this->seedsDir . '/order.log';

        file_put_contents(
            $this->seedsDir . '/0002_second.php',
            '<?php return function(PDO $pdo): void { file_put_contents(' . var_export($log, true) . ', "second", FILE_APPEND); };',
        );
        file_put_contents(
            $this->seedsDir . '/0001_first.php',
            '<?php return function(PDO $pdo): void { file_put_contents(' . var_export($log, true) . ', "first", FILE_APPEND); };',
        );

        $runner = new SeedRunner($this->pdo);
        $runner->run($this->seedsDir);

        $this->assertSame('firstsecond', file_get_contents($log));
    }

    public function testNonCallableReturnThrowsRuntimeException(): void
    {
        file_put_contents(
            $this->seedsDir . '/0001_bad.php',
            '<?php return "not a callable";',
        );

        $runner = new SeedRunner($this->pdo);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/does not return a callable/');

        $runner->run($this->seedsDir);
    }

    public function testFailedSeedThrowsRuntimeException(): void
    {
        file_put_contents(
            $this->seedsDir . '/0001_fail.php',
            '<?php return function(PDO $pdo): void { throw new \RuntimeException("seed boom"); };',
        );

        $runner = new SeedRunner($this->pdo);

        $this->expectException(RuntimeException::class);
        $runner->run($this->seedsDir);
    }

    public function testResetAdminPasswordFlagIsPassedToSeed(): void
    {
        $flag = $this->seedsDir . '/flag.log';

        file_put_contents(
            $this->seedsDir . '/0001_flag.php',
            '<?php return function(PDO $pdo, bool $resetAdminPassword = false): void {'
            . ' file_put_contents(' . var_export($flag, true) . ', $resetAdminPassword ? "1" : "0"); };',
        );

        $runner = new SeedRunner($this->pdo);
        $runner->run($this->seedsDir, resetAdminPassword: true);

        $this->assertSame('1', file_get_contents($flag));
    }

    /**
     * Core DoD: running seeds 3× in a row must not produce duplicate rows.
     */
    public function testHolidaysSeedIsIdempotentAfterThreeRuns(): void
    {
        // Create a SQLite-compatible holidays table
        $this->pdo->exec(
            'CREATE TABLE holidays (
                date  TEXT NOT NULL,
                state TEXT NOT NULL,
                name  TEXT NOT NULL,
                PRIMARY KEY (date, state)
            )'
        );

        // Seed file using SQLite-compatible INSERT OR REPLACE (upsert by PK)
        file_put_contents(
            $this->seedsDir . '/0001_holidays.php',
            '<?php return function(PDO $pdo): void {
                $holidays = [
                    ["date" => "2026-01-01", "state" => "DE", "name" => "Neujahr"],
                    ["date" => "2026-10-03", "state" => "DE", "name" => "Tag der Deutschen Einheit"],
                    ["date" => "2026-12-25", "state" => "DE", "name" => "1. Weihnachtstag"],
                ];
                $stmt = $pdo->prepare(
                    "INSERT OR REPLACE INTO holidays (date, state, name) VALUES (:date, :state, :name)"
                );
                foreach ($holidays as $h) { $stmt->execute($h); }
            };',
        );

        $runner = new SeedRunner($this->pdo);

        // Run 3 times
        $runner->run($this->seedsDir);
        $runner->run($this->seedsDir);
        $runner->run($this->seedsDir);

        $stmt = $this->pdo->query('SELECT COUNT(*) FROM holidays');
        $this->assertSame(3, (int) $stmt->fetchColumn());
    }
}
