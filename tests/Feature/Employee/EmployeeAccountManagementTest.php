<?php

declare(strict_types=1);

namespace App\Tests\Feature\Employee;

use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

class EmployeeAccountManagementTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $_SESSION = [];
        $_POST = [];

        $this->pdo = $this->buildSqlitePdo();
        $this->injectPdo($this->pdo);
    }

    protected function tearDown(): void
    {
        $this->resetDb();
        $_SESSION = [];
        $_POST = [];
    }

    public function testEmployeeSelfServiceUpdatesOnlyWhitelistedFieldsAndWritesAudit(): void
    {
        $employeeId = $this->createUser('employee@example.com', 'old-secret');
        $this->assignRole($employeeId, 'employee');
        $this->createProfile($employeeId, [
            'first_name' => 'Alt',
            'last_name' => 'Name',
            'birth_date' => '1999-01-01',
        ]);

        $result = dispatch(
            'POST',
            '/profile',
            [
                'first_name' => 'Neu',
                'last_name' => 'Name',
                'birth_date' => '2000-02-02',
                'csrf_token' => 'token-1',
            ],
            [
                'user_id' => $employeeId,
                '__user_roles' => ['employee'],
                '__csrf_token' => 'token-1',
            ],
        );

        $this->assertSame(303, $result['status']);
        $this->assertSame('/profile', $result['headers']['Location'] ?? null);

        $row = $this->pdo->query('SELECT first_name, birth_date FROM employee_profiles WHERE user_id = ' . $employeeId)
            ->fetch(PDO::FETCH_ASSOC);

        $this->assertSame('Neu', (string) $row['first_name']);
        $this->assertSame('1999-01-01', (string) $row['birth_date']);

        $audit = $this->pdo->query('SELECT action FROM user_admin_audit_log ORDER BY id ASC')
            ->fetchAll(PDO::FETCH_COLUMN);

        $this->assertSame(['self_profile_update'], $audit);
    }

    public function testCoordinationCannotSetInitialPasswordForNonEmployeeAccount(): void
    {
        $coordinationId = $this->createUser('coord@example.com', 'coord-secret');
        $targetId = $this->createUser('target@example.com', 'old-secret');
        $this->assignRole($coordinationId, 'coordination');

        $beforeHash = (string) $this->pdo->query('SELECT password_hash FROM users WHERE id = ' . $targetId)->fetchColumn();

        $result = dispatch(
            'POST',
            '/coordination/employees/' . $targetId . '/initial-password',
            [
                'new_password' => 'new-secret-123',
                'new_password_confirm' => 'new-secret-123',
                'csrf_token' => 'token-2',
            ],
            [
                'user_id' => $coordinationId,
                '__user_roles' => ['coordination'],
                '__csrf_token' => 'token-2',
            ],
        );

        $this->assertSame(403, $result['status']);

        $afterHash = (string) $this->pdo->query('SELECT password_hash FROM users WHERE id = ' . $targetId)->fetchColumn();
        $this->assertSame($beforeHash, $afterHash);

        $auditCount = (int) $this->pdo->query('SELECT COUNT(*) FROM user_admin_audit_log')->fetchColumn();
        $this->assertSame(0, $auditCount);
    }

    public function testCoordinationCanSetInitialPasswordForEmployeeAccountAndWritesAudit(): void
    {
        $coordinationId = $this->createUser('coord2@example.com', 'coord-secret');
        $targetId = $this->createUser('employee2@example.com', 'old-secret');

        $this->assignRole($coordinationId, 'coordination');
        $this->assignRole($targetId, 'employee');
        $this->createProfile($targetId, []);

        $result = dispatch(
            'POST',
            '/coordination/employees/' . $targetId . '/initial-password',
            [
                'new_password' => 'new-secret-123',
                'new_password_confirm' => 'new-secret-123',
                'csrf_token' => 'token-3',
            ],
            [
                'user_id' => $coordinationId,
                '__user_roles' => ['coordination'],
                '__csrf_token' => 'token-3',
            ],
        );

        $this->assertSame(303, $result['status']);
        $this->assertSame('/coordination/employees/' . $targetId, $result['headers']['Location'] ?? null);

        $newHash = (string) $this->pdo->query('SELECT password_hash FROM users WHERE id = ' . $targetId)->fetchColumn();
        $this->assertTrue(password_verify('new-secret-123', $newHash));

        $audit = $this->pdo->query('SELECT action FROM user_admin_audit_log ORDER BY id DESC LIMIT 1')
            ->fetchColumn();
        $this->assertSame('set_initial_password', $audit);
    }

    public function testCoordinationCanToggleEmployeeAccountActiveStateAndWritesAudit(): void
    {
        $coordinationId = $this->createUser('coord3@example.com', 'coord-secret');
        $targetId = $this->createUser('employee3@example.com', 'old-secret');

        $this->assignRole($coordinationId, 'coordination');
        $this->assignRole($targetId, 'employee');
        $this->createProfile($targetId, []);

        $result = dispatch(
            'POST',
            '/coordination/employees/' . $targetId . '/account',
            [
                'is_active' => '0',
                'reason' => 'Austritt',
                'csrf_token' => 'token-4',
            ],
            [
                'user_id' => $coordinationId,
                '__user_roles' => ['coordination'],
                '__csrf_token' => 'token-4',
            ],
        );

        $this->assertSame(303, $result['status']);

        $isActive = (int) $this->pdo->query('SELECT is_active FROM users WHERE id = ' . $targetId)->fetchColumn();
        $this->assertSame(0, $isActive);

        $audit = $this->pdo->query('SELECT action FROM user_admin_audit_log ORDER BY id DESC LIMIT 1')
            ->fetchColumn();
        $this->assertSame('deactivate_user', $audit);

        $reason = $this->pdo->query('SELECT reason FROM user_admin_audit_log ORDER BY id DESC LIMIT 1')
            ->fetchColumn();
        $this->assertSame('Austritt', (string) $reason);
    }

    public function testCoordinationDeactivateRequiresReason(): void
    {
        $coordinationId = $this->createUser('coord4@example.com', 'coord-secret');
        $targetId = $this->createUser('employee4@example.com', 'old-secret');

        $this->assignRole($coordinationId, 'coordination');
        $this->assignRole($targetId, 'employee');
        $this->createProfile($targetId, []);

        $result = dispatch(
            'POST',
            '/coordination/employees/' . $targetId . '/account',
            [
                'is_active' => '0',
                'reason' => '',
                'csrf_token' => 'token-5',
            ],
            [
                'user_id' => $coordinationId,
                '__user_roles' => ['coordination'],
                '__csrf_token' => 'token-5',
            ],
        );

        $this->assertSame(422, $result['status']);
        $this->assertSame(1, (int) $this->pdo->query('SELECT is_active FROM users WHERE id = ' . $targetId)->fetchColumn());
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM user_admin_audit_log')->fetchColumn());
    }

    public function testCoordinationSensitiveProfileUpdateRequiresReason(): void
    {
        $coordinationId = $this->createUser('coord5@example.com', 'coord-secret');
        $targetId = $this->createUser('employee5@example.com', 'old-secret');

        $this->assignRole($coordinationId, 'coordination');
        $this->assignRole($targetId, 'employee');
        $this->createProfile($targetId, [
            'first_name' => 'Alt',
            'last_name' => 'Name',
            'birth_date' => '1990-01-01',
        ]);

        $result = dispatch(
            'POST',
            '/coordination/employees/' . $targetId . '/profile',
            [
                'first_name' => 'Neu',
                'last_name' => 'Name',
                'birth_date' => '1991-01-01',
                'reason' => '',
                'csrf_token' => 'token-6',
            ],
            [
                'user_id' => $coordinationId,
                '__user_roles' => ['coordination'],
                '__csrf_token' => 'token-6',
            ],
        );

        $this->assertSame(422, $result['status']);
        $this->assertSame('1990-01-01', (string) $this->pdo->query('SELECT birth_date FROM employee_profiles WHERE user_id = ' . $targetId)->fetchColumn());
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM user_admin_audit_log')->fetchColumn());
    }

    private function buildSqlitePdo(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $pdo->exec('CREATE TABLE users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            email TEXT NOT NULL UNIQUE,
            password_hash TEXT NOT NULL,
            is_active INTEGER NOT NULL DEFAULT 1
        )');

        $pdo->exec('CREATE TABLE roles (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            `key` TEXT NOT NULL UNIQUE,
            name TEXT NOT NULL
        )');

        $pdo->exec('CREATE TABLE user_roles (
            user_id INTEGER NOT NULL,
            role_id INTEGER NOT NULL,
            PRIMARY KEY (user_id, role_id)
        )');

        $pdo->exec('CREATE TABLE employee_profiles (
            user_id INTEGER NOT NULL PRIMARY KEY,
            display_name TEXT NOT NULL DEFAULT "",
            first_name TEXT NULL,
            last_name TEXT NULL,
            address_text TEXT NULL,
            study_subjects_text TEXT NULL,
            study_program_text TEXT NULL,
            expected_graduation_date TEXT NULL,
            birth_date TEXT NULL,
            weekly_target_minutes INTEGER NULL,
            contract_type_key TEXT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )');

        $pdo->exec('CREATE TABLE user_admin_audit_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            actor_user_id INTEGER NOT NULL,
            target_user_id INTEGER NOT NULL,
            action TEXT NOT NULL,
            reason TEXT NULL,
            diff_json TEXT NOT NULL,
            created_at TEXT NOT NULL
        )');

        return $pdo;
    }

    private function createUser(string $email, string $password): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO users (email, password_hash, is_active) VALUES (:email, :hash, 1)');
        $stmt->execute([
            ':email' => $email,
            ':hash' => password_hash($password, PASSWORD_BCRYPT),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @param array<string, mixed> $data
     */
    private function createProfile(int $userId, array $data): void
    {
        $defaults = [
            'display_name' => 'Mitarbeiter',
            'first_name' => null,
            'last_name' => null,
            'address_text' => null,
            'study_subjects_text' => null,
            'study_program_text' => null,
            'expected_graduation_date' => null,
            'birth_date' => null,
            'weekly_target_minutes' => null,
            'contract_type_key' => null,
        ];

        $payload = array_merge($defaults, $data);

        $stmt = $this->pdo->prepare(
            'INSERT INTO employee_profiles
                (user_id, display_name, first_name, last_name, address_text, study_subjects_text, study_program_text,
                 expected_graduation_date, birth_date, weekly_target_minutes, contract_type_key)
             VALUES
                (:user_id, :display_name, :first_name, :last_name, :address_text, :study_subjects_text, :study_program_text,
                 :expected_graduation_date, :birth_date, :weekly_target_minutes, :contract_type_key)'
        );
        $stmt->execute([
            ':user_id' => $userId,
            ':display_name' => $payload['display_name'],
            ':first_name' => $payload['first_name'],
            ':last_name' => $payload['last_name'],
            ':address_text' => $payload['address_text'],
            ':study_subjects_text' => $payload['study_subjects_text'],
            ':study_program_text' => $payload['study_program_text'],
            ':expected_graduation_date' => $payload['expected_graduation_date'],
            ':birth_date' => $payload['birth_date'],
            ':weekly_target_minutes' => $payload['weekly_target_minutes'],
            ':contract_type_key' => $payload['contract_type_key'],
        ]);
    }

    private function assignRole(int $userId, string $roleKey): void
    {
        $stmt = $this->pdo->prepare('SELECT id FROM roles WHERE `key` = :key LIMIT 1');
        $stmt->execute([':key' => $roleKey]);
        $roleId = $stmt->fetchColumn();

        if ($roleId === false) {
            $this->pdo->prepare('INSERT INTO roles (`key`, name) VALUES (:key, :name)')
                ->execute([':key' => $roleKey, ':name' => $roleKey]);
            $roleId = (int) $this->pdo->lastInsertId();
        }

        $this->pdo->prepare('INSERT INTO user_roles (user_id, role_id) VALUES (:uid, :rid)')
            ->execute([':uid' => $userId, ':rid' => (int) $roleId]);
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
