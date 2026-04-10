<?php

declare(strict_types=1);

namespace App\Tests\Feature\Export;

use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * EX6.4 + EX6.4a – POST /export/pdf tests.
 *
 * Tests:
 *   1. Must-have: binary missing ⇒ 500.
 *   2. Must-have: empty data ⇒ 422 + Flash "Keine Daten für den Export."
 *   3. Must-have: success ⇒ response header contains filename.
 */
class ExportPdfTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $_SESSION = [];
        $_POST    = [];

        $this->pdo = $this->buildSqlitePdo();
        $this->injectPdo($this->pdo);
    }

    protected function tearDown(): void
    {
        $this->resetDb();
        $_SESSION = [];
        $_POST    = [];
        // Restore environment
        putenv('WKHTMLTOPDF_PATH');
        unset($_ENV['WKHTMLTOPDF_PATH']);
    }

    // =========================================================================
    // Must-have: binary missing ⇒ 500
    // =========================================================================

    public function testBinaryMissingReturns500(): void
    {
        $token   = bin2hex(random_bytes(16));
        $coordId = $this->createUser('coord@example.com', 'Coord');
        $this->insertApprovedEntry($coordId, '2026-04-10', '09:00:00', '17:00:00', 30, 450);

        // Point to a non-existent binary
        putenv('WKHTMLTOPDF_PATH=/nonexistent/wkhtmltopdf');
        $_ENV['WKHTMLTOPDF_PATH'] = '/nonexistent/wkhtmltopdf';

        $result = dispatch(
            'POST',
            '/export/pdf',
            [
                'csrf_token' => $token,
                'month'      => '2026-04',
                'scope'      => 'self',
            ],
            [
                'user_id'      => $coordId,
                '__user_roles' => ['coordination'],
                '__csrf_token' => $token,
            ],
        );

        $this->assertSame(500, $result['status']);
    }

    // =========================================================================
    // Must-have: empty data ⇒ 422 + Flash "Keine Daten für den Export."
    // =========================================================================

    public function testEmptyDataReturns422WithFlash(): void
    {
        $token   = bin2hex(random_bytes(16));
        $coordId = $this->createUser('coord2@example.com', 'Coord2');

        // No approved entries for this month

        $result = dispatch(
            'POST',
            '/export/pdf',
            [
                'csrf_token' => $token,
                'month'      => '2026-04',
                'scope'      => 'self',
            ],
            [
                'user_id'      => $coordId,
                '__user_roles' => ['coordination'],
                '__csrf_token' => $token,
            ],
        );

        $this->assertSame(422, $result['status']);
        $this->assertStringContainsString('Keine Daten für den Export.', $result['body']);
    }

    // =========================================================================
    // Must-have: success ⇒ Content-Disposition contains filename
    // =========================================================================

    public function testSuccessResponseContainsFilename(): void
    {
        // Find a real wkhtmltopdf binary
        $wk = trim((string) shell_exec('which wkhtmltopdf 2>/dev/null'));
        if ($wk === '' || !is_executable($wk)) {
            $this->markTestSkipped('wkhtmltopdf binary not available in test environment.');
        }

        $token   = bin2hex(random_bytes(16));
        $coordId = $this->createUser('coord3@example.com', 'Coord3');
        $this->insertApprovedEntry($coordId, '2026-04-10', '09:00:00', '17:00:00', 30, 450);

        putenv('WKHTMLTOPDF_PATH=' . $wk);
        $_ENV['WKHTMLTOPDF_PATH'] = $wk;

        $result = dispatch(
            'POST',
            '/export/pdf',
            [
                'csrf_token' => $token,
                'month'      => '2026-04',
                'scope'      => 'self',
            ],
            [
                'user_id'      => $coordId,
                '__user_roles' => ['coordination'],
                '__csrf_token' => $token,
            ],
        );

        $this->assertSame(200, $result['status']);
        $disposition = $result['headers']['Content-Disposition'] ?? '';
        $this->assertStringContainsString('trackly_export_2026-04.pdf', $disposition);
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    private function createUser(string $email, string $displayName = ''): int
    {
        $this->pdo->prepare(
            'INSERT INTO users (email, password_hash, is_active) VALUES (:email, :hash, 1)'
        )->execute([
            ':email' => $email,
            ':hash'  => password_hash('secret', PASSWORD_BCRYPT),
        ]);
        $id = (int) $this->pdo->lastInsertId();

        if ($displayName !== '') {
            $this->pdo->prepare(
                'INSERT INTO employee_profiles (user_id, display_name) VALUES (:uid, :dn)'
            )->execute([':uid' => $id, ':dn' => $displayName]);
        }

        return $id;
    }

    private function insertApprovedEntry(
        int    $userId,
        string $dateLocal,
        string $startTime,
        string $endTime,
        int    $breakMinutes,
        int    $netMinutes,
    ): int {
        $this->pdo->prepare(
            "INSERT INTO time_entries
                 (user_id, date_local, start_at, end_at, break_minutes, net_minutes,
                  entry_source, status, created_at, updated_at)
             VALUES
                 (:uid, :date, :date || ' ' || :start, :date || ' ' || :end,
                  :break, :net, 'manual', 'approved', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)"
        )->execute([
            ':uid'   => $userId,
            ':date'  => $dateLocal,
            ':start' => $startTime,
            ':end'   => $endTime,
            ':break' => $breakMinutes,
            ':net'   => $netMinutes,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    private function buildSqlitePdo(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $pdo->exec("CREATE TABLE users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            email TEXT NOT NULL UNIQUE,
            password_hash TEXT NOT NULL,
            is_active INTEGER NOT NULL DEFAULT 1
        )");
        $pdo->exec("CREATE TABLE employee_profiles (
            user_id INTEGER NOT NULL PRIMARY KEY,
            display_name TEXT NOT NULL DEFAULT ''
        )");
        $pdo->exec("CREATE TABLE time_entries (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            date_local TEXT NOT NULL,
            start_at TEXT NOT NULL,
            end_at TEXT NOT NULL,
            break_minutes INTEGER NOT NULL DEFAULT 0,
            net_minutes INTEGER NOT NULL,
            entry_source TEXT NOT NULL DEFAULT 'manual',
            status TEXT NOT NULL DEFAULT 'pending',
            approved_by_user_id INTEGER NULL,
            approved_at TEXT NULL,
            cancelled_by_user_id INTEGER NULL,
            cancelled_at TEXT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )");
        $pdo->exec("CREATE TABLE export_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            actor_user_id INTEGER NOT NULL,
            export_type TEXT NOT NULL,
            scope TEXT NOT NULL,
            target_user_id INTEGER NULL,
            month TEXT NOT NULL,
            row_count INTEGER NOT NULL DEFAULT 0,
            status TEXT NOT NULL DEFAULT 'ok',
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )");

        return $pdo;
    }

    private function injectPdo(PDO $pdo): void
    {
        $ref = new ReflectionProperty(\App\Db\Db::class, 'instance');
        $ref->setAccessible(true);
        $ref->setValue(null, $pdo);
    }

    private function resetDb(): void
    {
        $ref = new ReflectionProperty(\App\Db\Db::class, 'instance');
        $ref->setAccessible(true);
        $ref->setValue(null, null);
    }
}
