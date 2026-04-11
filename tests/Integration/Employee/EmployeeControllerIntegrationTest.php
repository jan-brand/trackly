<?php

declare(strict_types=1);

namespace App\Tests\Integration\Employee;

use App\Db\Db;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

class EmployeeControllerIntegrationTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $_SESSION = [];
        $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $this->pdo = $this->buildSqlitePdo();
        $this->injectDb($this->pdo);
    }

    protected function tearDown(): void
    {
        $this->resetDb();
        $_SESSION = [];
        $_POST = [];
    }

    public function testEmployeeUpdatesOwnSelfServiceFieldsOk(): void
    {
        $userId = $this->createUser('employee@example.com', 'employee');
        $this->assignRole($userId, 'employee');
        $this->createProfile($userId, [
            'first_name' => 'Alt',
            'last_name' => 'Name',
            'birth_date' => '1995-05-05',
            'weekly_target_minutes' => 600,
        ]);

        $result = dispatch(
            'POST',
            '/profile',
            [
                'first_name' => 'Neu',
                'last_name' => 'Name',
                'address_text' => 'Street 1',
                'study_subjects_text' => 'CS',
                'study_program_text' => 'Informatics',
                'expected_graduation_date' => '2030-06-30',
                'birth_date' => '2000-01-01',
                'weekly_target_minutes' => '999',
                'csrf_token' => 'token-1',
            ],
            [
                'user_id' => $userId,
                '__user_roles' => ['employee'],
                '__csrf_token' => 'token-1',
            ],
        );

        $this->assertSame(303, $result['status']);

        $row = $this->pdo->query(
            'SELECT first_name, last_name, address_text, study_subjects_text, study_program_text, expected_graduation_date, birth_date, weekly_target_minutes
               FROM employee_profiles
              WHERE user_id = ' . $userId
        )->fetch(PDO::FETCH_ASSOC);

        $this->assertSame('Neu', $row['first_name']);
        $this->assertSame('Name', $row['last_name']);
        $this->assertSame('Street 1', $row['address_text']);
        $this->assertSame('CS', $row['study_subjects_text']);
        $this->assertSame('Informatics', $row['study_program_text']);
        $this->assertSame('2030-06-30', $row['expected_graduation_date']);
        $this->assertSame('1995-05-05', $row['birth_date']);
        $this->assertSame(600, (int) $row['weekly_target_minutes']);

        $audit = $this->pdo->query('SELECT action FROM user_admin_audit_log ORDER BY id DESC LIMIT 1')->fetchColumn();
        $this->assertSame('self_profile_update', $audit);
    }

    public function testEmployeeTriesToDeactivateOwnAccountForbidden(): void
    {
        $userId = $this->createUser('employee2@example.com', 'employee');
        $this->assignRole($userId, 'employee');
        $this->createProfile($userId, []);

        $result = dispatch(
            'POST',
            '/coordination/employees/' . $userId . '/account',
            [
                'is_active' => '0',
                'csrf_token' => 'token-2',
            ],
            [
                'user_id' => $userId,
                '__user_roles' => ['employee'],
                '__csrf_token' => 'token-2',
            ],
        );

        $this->assertSame(403, $result['status']);
    }

    public function testCoordinationCanDeactivateEmployeeAccountOk(): void
    {
        $coordinationId = $this->createUser('coord@example.com', 'coordination');
        $employeeId = $this->createUser('employee3@example.com', 'employee');
        $this->assignRole($coordinationId, 'coordination');
        $this->assignRole($employeeId, 'employee');
        $this->createProfile($employeeId, []);

        $result = dispatch(
            'POST',
            '/coordination/employees/' . $employeeId . '/account',
            [
                'is_active' => '0',
                'reason' => 'Vertragsende',
                'csrf_token' => 'token-3',
            ],
            [
                'user_id' => $coordinationId,
                '__user_roles' => ['coordination'],
                '__csrf_token' => 'token-3',
            ],
        );

        $this->assertSame(303, $result['status']);
        $this->assertSame(0, (int) $this->pdo->query('SELECT is_active FROM users WHERE id = ' . $employeeId)->fetchColumn());
    }

    public function testCoordinationTriesToDeactivateAdminAccountForbidden(): void
    {
        $coordinationId = $this->createUser('coord2@example.com', 'coordination');
        $adminId = $this->createUser('admin@example.com', 'admin');
        $this->assignRole($coordinationId, 'coordination');
        $this->assignRole($adminId, 'admin');

        $result = dispatch(
            'POST',
            '/coordination/employees/' . $adminId . '/account',
            [
                'is_active' => '0',
                'csrf_token' => 'token-4',
            ],
            [
                'user_id' => $coordinationId,
                '__user_roles' => ['coordination'],
                '__csrf_token' => 'token-4',
            ],
        );

        $this->assertSame(403, $result['status']);
        $this->assertSame(1, (int) $this->pdo->query('SELECT is_active FROM users WHERE id = ' . $adminId)->fetchColumn());
    }

    public function testCoordinationSetInitialPasswordForEmployeeAccountOk(): void
    {
        $coordinationId = $this->createUser('coord3@example.com', 'coordination');
        $employeeId = $this->createUser('employee4@example.com', 'old-secret');
        $this->assignRole($coordinationId, 'coordination');
        $this->assignRole($employeeId, 'employee');
        $this->createProfile($employeeId, []);

        $result = dispatch(
            'POST',
            '/coordination/employees/' . $employeeId . '/initial-password',
            [
                'new_password' => 'new-secret-123',
                'new_password_confirm' => 'new-secret-123',
                'csrf_token' => 'token-5',
            ],
            [
                'user_id' => $coordinationId,
                '__user_roles' => ['coordination'],
                '__csrf_token' => 'token-5',
            ],
        );

        $this->assertSame(303, $result['status']);
        $this->assertTrue(password_verify('new-secret-123', (string) $this->pdo->query('SELECT password_hash FROM users WHERE id = ' . $employeeId)->fetchColumn()));
        $this->assertSame('set_initial_password', $this->pdo->query('SELECT action FROM user_admin_audit_log ORDER BY id DESC LIMIT 1')->fetchColumn());
    }

    public function testCoordinationSetInitialPasswordForNonEmployeeForbidden(): void
    {
        $coordinationId = $this->createUser('coord4@example.com', 'coordination');
        $targetId = $this->createUser('target@example.com', 'old-secret');
        $this->assignRole($coordinationId, 'coordination');
        $this->assignRole($targetId, 'admin');

        $result = dispatch(
            'POST',
            '/coordination/employees/' . $targetId . '/initial-password',
            [
                'new_password' => 'new-secret-123',
                'new_password_confirm' => 'new-secret-123',
                'csrf_token' => 'token-6',
            ],
            [
                'user_id' => $coordinationId,
                '__user_roles' => ['coordination'],
                '__csrf_token' => 'token-6',
            ],
        );

        $this->assertSame(403, $result['status']);
    }

    public function testCoordinationEmployeesNewShowsForm(): void
    {
        $coordinationId = $this->createUser('coord-new@example.com', 'coordination');
        $this->assignRole($coordinationId, 'coordination');

        $result = dispatch(
            'GET',
            '/coordination/employees/new',
            [],
            [
                'user_id' => $coordinationId,
                '__user_roles' => ['coordination'],
            ],
        );

        $this->assertSame(200, $result['status']);
        $this->assertStringContainsString('Neues Mitarbeitenden-Konto', $result['body']);
    }

    public function testCoordinationCanCreateEmployeeAccount(): void
    {
        $coordinationId = $this->createUser('coord-create@example.com', 'coordination');
        $this->assignRole($coordinationId, 'coordination');

        $this->createRole('employee');

        $token = 'token-new-1';

        $result = dispatch(
            'POST',
            '/coordination/employees/new',
            [
                'email' => 'created@example.com',
                'first_name' => 'Max',
                'last_name' => 'Mustermann',
                'contract_type_key' => 'werkstudent',
                'csrf_token' => $token,
                'create_account' => '1',
            ],
            [
                'user_id' => $coordinationId,
                '__user_roles' => ['coordination'],
                '__csrf_token' => $token,
            ],
        );

        $this->assertSame(303, $result['status']);
        $createdUserId = (int) $this->pdo->query('SELECT id FROM users WHERE email = "created@example.com"')->fetchColumn();
        $this->assertGreaterThan(0, $createdUserId);
        $this->assertSame('/coordination/employees/' . $createdUserId, $result['headers']['Location'] ?? null);

        $row = $this->pdo->query('SELECT email, is_active FROM users WHERE email = "created@example.com"')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('created@example.com', $row['email']);
        $this->assertSame(1, (int) $row['is_active']);

        $profile = $this->pdo->query('SELECT contract_type_key FROM employee_profiles WHERE user_id = ' . $createdUserId)->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('werkstudent', $profile['contract_type_key']);

        $roles = $this->pdo->query(
            'SELECT r.`key`
               FROM roles r
               JOIN user_roles ur ON ur.role_id = r.id
              WHERE ur.user_id = ' . $createdUserId
        )->fetchAll(PDO::FETCH_COLUMN);
        $this->assertContains('employee', $roles);

        $auditAction = $this->pdo->query('SELECT action FROM user_admin_audit_log ORDER BY id DESC LIMIT 1')->fetchColumn();
        $this->assertSame('create_employee_account', $auditAction);

        $emails = \App\Support\EmailQueue::all();
        $this->assertCount(1, $emails);
        $this->assertSame('created@example.com', $emails[0]['to']);
        $this->assertSame('Dein Trackly-Zugang', $emails[0]['subject']);
        $this->assertStringContainsString('Initial-Passwort', $emails[0]['body']);
    }

    public function testCoordinationCanCreateEmployeeWithoutLoginAccount(): void
    {
        $coordinationId = $this->createUser('coord-no-login@example.com', 'coordination');
        $this->assignRole($coordinationId, 'coordination');

        $token = 'token-new-2';

        $result = dispatch(
            'POST',
            '/coordination/employees/new',
            [
                'email' => 'no-login@example.com',
                'first_name' => 'Ohne',
                'last_name' => 'Konto',
                'contract_type_key' => 'minijob',
                'csrf_token' => $token,
                'create_account' => '0',
            ],
            [
                'user_id' => $coordinationId,
                '__user_roles' => ['coordination'],
                '__csrf_token' => $token,
            ],
        );

        $this->assertSame(303, $result['status']);
        $createdUserId = (int) $this->pdo->query('SELECT id FROM users WHERE email = "no-login@example.com"')->fetchColumn();
        $this->assertGreaterThan(0, $createdUserId);

        $isActive = (int) $this->pdo->query('SELECT is_active FROM users WHERE id = ' . $createdUserId)->fetchColumn();
        $this->assertSame(0, $isActive);

        $profile = $this->pdo->query('SELECT contract_type_key FROM employee_profiles WHERE user_id = ' . $createdUserId)->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('minijob', $profile['contract_type_key']);

        $roleCount = (int) $this->pdo->query('SELECT COUNT(*) FROM user_roles WHERE user_id = ' . $createdUserId)->fetchColumn();
        $this->assertSame(0, $roleCount);

        $emails = \App\Support\EmailQueue::all();
        $this->assertCount(0, $emails);
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

    private function createRole(string $roleKey): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO roles (`key`, name) VALUES (:key, :name)');
        $stmt->execute([
            ':key' => $roleKey,
            ':name' => $roleKey,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @param array<string, mixed> $data
     */
    private function createProfile(int $userId, array $data): void
    {
        $payload = array_merge([
            'display_name' => 'Display Name',
            'first_name' => null,
            'last_name' => null,
            'address_text' => null,
            'study_subjects_text' => null,
            'study_program_text' => null,
            'expected_graduation_date' => null,
            'birth_date' => null,
            'weekly_target_minutes' => null,
            'contract_type_key' => null,
        ], $data);

        $stmt = $this->pdo->prepare('INSERT INTO employee_profiles
            (user_id, display_name, first_name, last_name, address_text, study_subjects_text, study_program_text, expected_graduation_date, birth_date, weekly_target_minutes, contract_type_key)
            VALUES
            (:user_id, :display_name, :first_name, :last_name, :address_text, :study_subjects_text, :study_program_text, :expected_graduation_date, :birth_date, :weekly_target_minutes, :contract_type_key)');
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
        $this->pdo->prepare('INSERT INTO roles (`key`, name) VALUES (:key, :name)')
            ->execute([':key' => $roleKey, ':name' => $roleKey]);
        $roleId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare('INSERT INTO user_roles (user_id, role_id) VALUES (:user_id, :role_id)')
            ->execute([':user_id' => $userId, ':role_id' => $roleId]);
    }

    private function injectDb(PDO $pdo): void
    {
        $ref = new ReflectionProperty(Db::class, 'instance');
        $ref->setAccessible(true);
        $ref->setValue(null, $pdo);
    }

    private function resetDb(): void
    {
        $ref = new ReflectionProperty(Db::class, 'instance');
        $ref->setAccessible(true);
        $ref->setValue(null, null);
    }
}
