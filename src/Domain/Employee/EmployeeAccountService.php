<?php

declare(strict_types=1);

namespace App\Domain\Employee;

use App\Http\ForbiddenException;
use PDO;

/**
 * Handles employee profile/account mutations with strict field whitelists.
 */
final class EmployeeAccountService
{
    private const SELF_SERVICE_FIELDS = [
        'first_name',
        'last_name',
        'address_text',
        'study_subjects_text',
        'study_program_text',
        'expected_graduation_date',
    ];

    private const MANAGEMENT_FIELDS = [
        'first_name',
        'last_name',
        'address_text',
        'study_subjects_text',
        'study_program_text',
        'expected_graduation_date',
        'birth_date',
        'weekly_target_minutes',
        'contract_type_key',
    ];

    private readonly UserAdminAuditService $audit;

    public function __construct(private readonly PDO $pdo)
    {
        $this->audit = new UserAdminAuditService($pdo);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listEmployeeAccounts(): array
    {
        $stmt = $this->pdo->query(
            "SELECT
                u.id,
                u.email,
                u.is_active,
                ep.display_name,
                ep.first_name,
                ep.last_name,
                ep.expected_graduation_date,
                ep.contract_type_key
             FROM users u
             JOIN user_roles ur ON ur.user_id = u.id
             JOIN roles r ON r.id = ur.role_id AND r.`key` = 'employee'
             JOIN employee_profiles ep ON ep.user_id = u.id
             ORDER BY u.email ASC"
        );

        return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    }

    /**
     * @return array<string, mixed>
     */
    public function getProfileForView(int $targetUserId, bool $restrictToEmployeeAccount): array
    {
        if ($restrictToEmployeeAccount && !$this->isEmployeeAccount($targetUserId)) {
            throw new ForbiddenException('Target account is not an employee account.');
        }

        $stmt = $this->pdo->prepare(
            'SELECT
                u.id,
                u.email,
                u.is_active,
                ep.display_name,
                ep.first_name,
                ep.last_name,
                ep.address_text,
                ep.study_subjects_text,
                ep.study_program_text,
                ep.expected_graduation_date,
                ep.birth_date,
                ep.weekly_target_minutes,
                ep.contract_type_key
             FROM users u
             LEFT JOIN employee_profiles ep ON ep.user_id = u.id
             WHERE u.id = :id
             LIMIT 1'
        );
        $stmt->execute([':id' => $targetUserId]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false || $row['display_name'] === null) {
            throw new ForbiddenException('Profile not found.');
        }

        return $row;
    }

    public function updateOwnProfile(int $actorUserId, array $fields): void
    {
        if (!$this->isEmployeeAccount($actorUserId)) {
            throw new ForbiddenException('Only employee accounts may use profile self-service.');
        }

        $old = $this->getProfileSnapshot($actorUserId);
        $this->updateEmployeeProfile($actorUserId, $this->filterAllowedFields($fields, self::SELF_SERVICE_FIELDS));
        $new = $this->getProfileSnapshot($actorUserId);

        $this->audit->record($actorUserId, $actorUserId, 'self_profile_update', null, $old, $new);
    }

    public function updateManagedProfile(
        int $actorUserId,
        int $targetUserId,
        array $fields,
        ?string $reason,
        bool $restrictToEmployeeAccount,
    ): void
    {
        if ($restrictToEmployeeAccount && !$this->isEmployeeAccount($targetUserId)) {
            throw new ForbiddenException('Target account is not an employee account.');
        }

        $old = $this->getProfileSnapshot($targetUserId);
        $this->updateEmployeeProfile($targetUserId, $this->filterAllowedFields($fields, self::MANAGEMENT_FIELDS));
        $new = $this->getProfileSnapshot($targetUserId);

        $this->audit->record($actorUserId, $targetUserId, 'admin_profile_update', $reason, $old, $new);
    }

    public function updateManagedAccountActiveState(
        int $actorUserId,
        int $targetUserId,
        bool $isActive,
        ?string $reason,
        bool $restrictToEmployeeAccount,
    ): void {
        if ($restrictToEmployeeAccount && !$this->isEmployeeAccount($targetUserId)) {
            throw new ForbiddenException('Target account is not an employee account.');
        }

        $old = $this->getAccountSnapshot($targetUserId);

        $stmt = $this->pdo->prepare('UPDATE users SET is_active = :active WHERE id = :id');
        $stmt->execute([
            ':active' => $isActive ? 1 : 0,
            ':id' => $targetUserId,
        ]);

        $new = $this->getAccountSnapshot($targetUserId);

        $this->audit->record(
            $actorUserId,
            $targetUserId,
            $isActive ? 'activate_user' : 'deactivate_user',
            $reason,
            $old,
            $new,
        );
    }

    public function setManagedInitialPassword(
        int $actorUserId,
        int $targetUserId,
        string $newPassword,
        ?string $reason,
        bool $restrictToEmployeeAccount,
    ): void {
        if ($restrictToEmployeeAccount && !$this->isEmployeeAccount($targetUserId)) {
            throw new ForbiddenException('Target account is not an employee account.');
        }

        $old = $this->getPasswordSnapshot($targetUserId);

        $hash = password_hash($newPassword, PASSWORD_BCRYPT);
        $stmt = $this->pdo->prepare('UPDATE users SET password_hash = :hash WHERE id = :id');
        $stmt->execute([
            ':hash' => $hash,
            ':id' => $targetUserId,
        ]);

        $new = $this->getPasswordSnapshot($targetUserId);

        $this->audit->record(
            $actorUserId,
            $targetUserId,
            'set_initial_password',
            $reason,
            $old,
            $new,
        );
    }

    /**
     * @return array{rows: list<array<string, mixed>>, has_more: bool}
     */
    public function listAuditEntries(int $targetUserId, int $limit, int $offset): array
    {
        return $this->audit->listForTarget($targetUserId, $limit, $offset);
    }

    public function isEmployeeAccount(int $userId): bool
    {
        $stmt = $this->pdo->prepare(
            "SELECT 1
             FROM users u
             JOIN user_roles ur ON ur.user_id = u.id
             JOIN roles r ON r.id = ur.role_id AND r.`key` = 'employee'
             JOIN employee_profiles ep ON ep.user_id = u.id
             WHERE u.id = :id
             LIMIT 1"
        );
        $stmt->execute([':id' => $userId]);

        return $stmt->fetchColumn() !== false;
    }

    private function updateEmployeeProfile(int $userId, array $fields): void
    {
        $updates = [];
        $params = [':user_id' => $userId];

        foreach ($fields as $key => $value) {
            if (!array_key_exists($key, $fields)) {
                continue;
            }

            $updates[] = $key . ' = :' . $key;
            $params[':' . $key] = $value;
        }

        if (empty($updates)) {
            return;
        }

        // Keep display_name in sync when first/last name changes.
        $firstName = array_key_exists('first_name', $fields)
            ? (string) ($fields['first_name'] ?? '')
            : (string) ($this->getProfileSnapshot($userId)['first_name'] ?? '');

        $lastName = array_key_exists('last_name', $fields)
            ? (string) ($fields['last_name'] ?? '')
            : (string) ($this->getProfileSnapshot($userId)['last_name'] ?? '');

        $displayName = trim($firstName . ' ' . $lastName);
        if ($displayName !== '') {
            $updates[] = 'display_name = :display_name';
            $params[':display_name'] = $displayName;
        }

        $sql = 'UPDATE employee_profiles SET ' . implode(', ', $updates) . ' WHERE user_id = :user_id';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
    }

    /**
     * @param array<string, mixed> $fields
     * @param string[] $allowedFields
     * @return array<string, mixed>
     */
    private function filterAllowedFields(array $fields, array $allowedFields): array
    {
        $filtered = [];

        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $fields)) {
                $filtered[$field] = $fields[$field];
            }
        }

        return $filtered;
    }

    /**
     * @return array<string, mixed>
     */
    private function getProfileSnapshot(int $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT
                u.id,
                u.email,
                u.is_active,
                ep.display_name,
                ep.first_name,
                ep.last_name,
                ep.address_text,
                ep.study_subjects_text,
                ep.study_program_text,
                ep.expected_graduation_date,
                ep.birth_date,
                ep.weekly_target_minutes,
                ep.contract_type_key
             FROM users u
             JOIN employee_profiles ep ON ep.user_id = u.id
             WHERE u.id = :id
             LIMIT 1'
        );
        $stmt->execute([':id' => $userId]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new ForbiddenException('Profile not found.');
        }

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function getAccountSnapshot(int $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, email, is_active FROM users WHERE id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $userId]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new ForbiddenException('Account not found.');
        }

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function getPasswordSnapshot(int $userId): array
    {
        $stmt = $this->pdo->prepare('SELECT id, password_hash FROM users WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $userId]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new ForbiddenException('Account not found.');
        }

        return $row;
    }
}
