<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Db\MigrationRunner;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class MigrationRunnerTest extends TestCase
{
    private PDO $pdo;
    private string $migrationsDir;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $this->migrationsDir = sys_get_temp_dir() . '/trackly_migrations_' . uniqid();
        mkdir($this->migrationsDir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->migrationsDir . '/*') ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        rmdir($this->migrationsDir);
    }

    public function testFirstRunAppliesMigrations(): void
    {
        file_put_contents(
            $this->migrationsDir . '/2026-01-01_0001_create_foo.php',
            '<?php return function(PDO $pdo): void { $pdo->exec("CREATE TABLE foo (id INTEGER PRIMARY KEY)"); };',
        );

        $runner = new MigrationRunner($this->pdo);
        $result = $runner->run($this->migrationsDir);

        $this->assertSame(1, $result['applied']);
        $this->assertSame(0, $result['skipped']);
    }

    public function testSecondRunAppliesZeroMigrations(): void
    {
        file_put_contents(
            $this->migrationsDir . '/2026-01-01_0001_noop.php',
            '<?php return function(PDO $pdo): void {};',
        );

        $runner = new MigrationRunner($this->pdo);

        // First run
        $result1 = $runner->run($this->migrationsDir);
        $this->assertSame(1, $result1['applied']);

        // Second run
        $result2 = $runner->run($this->migrationsDir);
        $this->assertSame(0, $result2['applied']);
        $this->assertSame(1, $result2['skipped']);

        // schema_migrations count is unchanged after second run
        $stmt = $this->pdo->query('SELECT COUNT(*) FROM schema_migrations');
        $this->assertSame(1, (int) $stmt->fetchColumn());
    }

    public function testMigrationsRunInLexicographicOrder(): void
    {
        $logFile = $this->migrationsDir . '/order.log';

        file_put_contents(
            $this->migrationsDir . '/2026-01-01_0002_second.php',
            '<?php return function(PDO $pdo): void { file_put_contents(' . var_export($logFile, true) . ', "second", FILE_APPEND); };',
        );
        file_put_contents(
            $this->migrationsDir . '/2026-01-01_0001_first.php',
            '<?php return function(PDO $pdo): void { file_put_contents(' . var_export($logFile, true) . ', "first", FILE_APPEND); };',
        );

        $runner = new MigrationRunner($this->pdo);
        $runner->run($this->migrationsDir);

        $this->assertSame('firstsecond', file_get_contents($logFile));
    }

    public function testNonCallableReturnThrowsRuntimeException(): void
    {
        file_put_contents(
            $this->migrationsDir . '/2026-01-01_0001_bad.php',
            '<?php return "not a callable";',
        );

        $runner = new MigrationRunner($this->pdo);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/does not return a callable/');

        $runner->run($this->migrationsDir);
    }

    public function testFailedMigrationRollsBackAndThrows(): void
    {
        file_put_contents(
            $this->migrationsDir . '/2026-01-01_0001_fail.php',
            '<?php return function(PDO $pdo): void { throw new \RuntimeException("boom"); };',
        );

        $runner = new MigrationRunner($this->pdo);

        $this->expectException(RuntimeException::class);
        $runner->run($this->migrationsDir);

        // Nothing should be in schema_migrations after rollback
        $stmt = $this->pdo->query('SELECT COUNT(*) FROM schema_migrations');
        $this->assertSame(0, (int) $stmt->fetchColumn());
    }
}
