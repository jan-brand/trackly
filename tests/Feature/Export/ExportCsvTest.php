<?php

declare(strict_types=1);

namespace App\Tests\Feature\Export;

use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * EX6.3 + EX6.2a – POST /export/csv tests.
 *
 * Tests:
 *   1. Must-have: employee POST scope=all_users ⇒ result contains only self.
 *   2. Unknown scope ⇒ 400.
 *   3. CSV contains `;` delimiter and CRLF line endings.
 *   4. Formula injection: display_name starting with `=` ⇒ prefixed with `'`.
 *   5. CSV quoting: display_name with embedded quotes ⇒ correctly escaped.
 */
class ExportCsvTest extends TestCase
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
    }

    // =========================================================================
    // Must-have: employee scope=all_users ⇒ result only contains self
    // =========================================================================

    public function testEmployeeScopeAllUsersIsIgnored(): void
    {
        $token = bin2hex(random_bytes(16));

        $empId   = $this->createUser('emp@example.com', 'Emp User');
        $otherId = $this->createUser('other@example.com', 'Other User');

        // emp has one approved entry this month; other has one too
        $this->insertApprovedEntry($empId,   '2026-04-05', '09:00:00', '17:00:00', 30, 450);
        $this->insertApprovedEntry($otherId, '2026-04-06', '08:00:00', '16:00:00', 30, 450);

        $result = dispatch(
            'POST',
            '/export/csv',
            [
                'csrf_token'    => $token,
                'month'         => '2026-04',
                'scope'         => 'all_users',
            ],
            [
                'user_id'      => $empId,
                '__user_roles' => ['employee'],
                '__csrf_token' => $token,
            ],
        );

        $this->assertSame(200, $result['status']);
        $body = $result['body'];

        // Should contain emp's entry
        $this->assertStringContainsString('Emp User', $body);

        // Should NOT contain other user's entry
        $this->assertStringNotContainsString('Other User', $body);
    }

    // =========================================================================
    // Unknown scope value ⇒ 400
    // =========================================================================

    public function testUnknownScopeReturns400(): void
    {
        $token   = bin2hex(random_bytes(16));
        $coordId = $this->createUser('coord@example.com', 'Coord User');

        $result = dispatch(
            'POST',
            '/export/csv',
            [
                'csrf_token' => $token,
                'month'      => '2026-04',
                'scope'      => 'invalid_scope',
            ],
            [
                'user_id'      => $coordId,
                '__user_roles' => ['coordination'],
                '__csrf_token' => $token,
            ],
        );

        $this->assertSame(400, $result['status']);
    }

    // =========================================================================
    // EX6.5 Must-have: CSV delimiter `;` and CRLF
    // =========================================================================

    public function testCsvUsesDelimiterAndCrlf(): void
    {
        $token = bin2hex(random_bytes(16));
        $empId = $this->createUser('emp2@example.com', 'Test User');
        $this->insertApprovedEntry($empId, '2026-04-10', '09:00:00', '17:00:00', 30, 450);

        $result = dispatch(
            'POST',
            '/export/csv',
            [
                'csrf_token' => $token,
                'month'      => '2026-04',
            ],
            [
                'user_id'      => $empId,
                '__user_roles' => ['employee'],
                '__csrf_token' => $token,
            ],
        );

        $this->assertSame(200, $result['status']);

        // Remove BOM if present
        $body = ltrim($result['body'], "\xEF\xBB\xBF");

        // Every non-empty line must contain `;`
        foreach (explode("\r\n", rtrim($body, "\r\n")) as $line) {
            if ($line !== '') {
                $this->assertStringContainsString(';', $line, 'Line must use ; delimiter');
            }
        }

        // Lines must end with \r\n
        $this->assertStringContainsString("\r\n", $body, 'CSV must use CRLF line endings');
    }

    // =========================================================================
    // EX6.5 Must-have: formula injection – display_name starts with `=SUM(…)`
    // =========================================================================

    public function testFormulaInjectionIsMitigated(): void
    {
        $token = bin2hex(random_bytes(16));

        // Create user with dangerous display_name
        $coordId = $this->createUser('coord2@example.com', '=SUM(A1:A2)');

        $this->insertApprovedEntry($coordId, '2026-04-10', '09:00:00', '17:00:00', 30, 450);

        $result = dispatch(
            'POST',
            '/export/csv',
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
        $body = ltrim($result['body'], "\xEF\xBB\xBF");

        // The dangerous name must be prefixed with `'`
        $this->assertStringContainsString("'=SUM(A1:A2)", $body);
        $this->assertStringNotContainsString(';=SUM(A1:A2)', $body);
    }

    // =========================================================================
    // EX6.5 Must-have: CSV quoting – embedded quotes in display_name
    // =========================================================================

    public function testCsvQuotesEmbeddedQuotesInDisplayName(): void
    {
        $token = bin2hex(random_bytes(16));

        $coordId = $this->createUser('coord3@example.com', 'Max "Mustermann"');
        $this->insertApprovedEntry($coordId, '2026-04-10', '09:00:00', '17:00:00', 30, 450);

        $result = dispatch(
            'POST',
            '/export/csv',
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
        $body = ltrim($result['body'], "\xEF\xBB\xBF");

        // Field should be quoted and inner quotes doubled
        $this->assertStringContainsString('"Max ""Mustermann"""', $body);
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
