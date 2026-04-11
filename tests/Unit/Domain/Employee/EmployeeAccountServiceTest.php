<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Employee;

use App\Db\Db;
use App\Domain\Employee\EmployeeAccountService;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

class EmployeeAccountServiceTest extends TestCase
{
    private PDO $pdo;
    private EmployeeAccountService $service;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $this->createSchema($this->pdo);
        $this->injectDb($this->pdo);

        $this->service = new EmployeeAccountService($this->pdo);
    }

    protected function tearDown(): void
    {
        $this->resetDb();
    }

    public function testIsEmployeeAccountReturnsTrueWhenRoleAndProfileExist(): void
    {
        $userId = $this->createUser('employee@example.com');
        $this->assignRole($userId, 'employee');
        $this->createProfile($userId);

        $this->assertTrue($this->service->isEmployeeAccount($userId));
    }

    public function testIsEmployeeAccountReturnsFalseWhenRoleExistsWithoutProfile(): void
    {
        $userId = $this->createUser('employee-no-profile@example.com');
        $this->assignRole($userId, 'employee');

        $this->assertFalse($this->service->isEmployeeAccount($userId));
    }

    public function testIsEmployeeAccountReturnsFalseWhenProfileExistsWithoutRole(): void
    {
        $userId = $this->createUser('profile-no-role@example.com');
        $this->createProfile($userId);

        $this->assertFalse($this->service->isEmployeeAccount($userId));
    }

    public function testSelfServiceIgnoresForbiddenFields(): void
    {
        $userId = $this->createUser('employee-whitelist@example.com');
        $this->assignRole($userId, 'employee');
        $this->createProfile($userId, [
            'first_name' => 'Alt',
            'last_name' => 'Name',
            'birth_date' => '1990-01-01',
            'weekly_target_minutes' => 900,
        ]);

        $this->service->updateOwnProfile($userId, [
            'first_name' => 'Neu',
            'last_name' => 'Name',
            'birth_date' => '2001-02-03',
            'weekly_target_minutes' => 1234,
            'contract_type_key' => 'temp',
        ]);

        $row = $this->pdo->query(
            'SELECT first_name, last_name, birth_date, weekly_target_minutes, contract_type_key
               FROM employee_profiles
              WHERE user_id = ' . $userId
        )->fetch(PDO::FETCH_ASSOC);

        $this->assertSame('Neu', $row['first_name']);
        $this->assertSame('Name', $row['last_name']);
        $this->assertSame('1990-01-01', $row['birth_date']);
        $this->assertSame(900, (int) $row['weekly_target_minutes']);
        $this->assertNull($row['contract_type_key']);
    }

    private function createSchema(PDO $pdo): void
    {
        $pdo->exec('CREATE TABLE users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            email TEXT NOT NULL UNIQUE,
            password_hash TEXT NOT NULL DEFAULT "",
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

        $pdo->exec('CREATE TABLE employee_account_audit_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            target_user_id INTEGER NOT NULL,
            actor_user_id INTEGER NOT NULL,
            action TEXT NOT NULL,
            reason TEXT NULL,
            old_json TEXT NULL,
            new_json TEXT NOT NULL,
            created_at TEXT NOT NULL
        )');
    }

    private function createUser(string $email): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO users (email, password_hash, is_active) VALUES (:email, :hash, 1)'
        );
        $stmt->execute([
            ':email' => $email,
            ':hash' => password_hash('secret', PASSWORD_BCRYPT),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function createProfile(int $userId, array $overrides = []): void
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
        ], $overrides);

        $stmt = $this->pdo->prepare(
            'INSERT INTO employee_profiles
                (user_id, display_name, first_name, last_name, address_text, study_subjects_text, study_program_text, expected_graduation_date, birth_date, weekly_target_minutes, contract_type_key)
             VALUES
                (:user_id, :display_name, :first_name, :last_name, :address_text, :study_subjects_text, :study_program_text, :expected_graduation_date, :birth_date, :weekly_target_minutes, :contract_type_key)'
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
