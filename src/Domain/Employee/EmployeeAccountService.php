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
    public function __construct(private readonly PDO $pdo) {}

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
        $this->updateEmployeeProfile($actorUserId, $fields);
        $new = $this->getProfileSnapshot($actorUserId);

        $this->appendAudit($actorUserId, $actorUserId, 'self_service_profile_update', null, $old, $new);
    }

    public function updateManagedProfile(int $actorUserId, int $targetUserId, array $fields, bool $restrictToEmployeeAccount): void
    {
        if ($restrictToEmployeeAccount && !$this->isEmployeeAccount($targetUserId)) {
            throw new ForbiddenException('Target account is not an employee account.');
        }

        $old = $this->getProfileSnapshot($targetUserId);
        $this->updateEmployeeProfile($targetUserId, $fields);
        $new = $this->getProfileSnapshot($targetUserId);

        $this->appendAudit($actorUserId, $targetUserId, 'coordination_profile_update', null, $old, $new);
    }

    public function updateManagedAccountActiveState(
        int $actorUserId,
        int $targetUserId,
        bool $isActive,
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

        $this->appendAudit(
            $actorUserId,
            $targetUserId,
            'coordination_account_active_update',
            null,
            $old,
            $new,
        );
    }

    public function setManagedInitialPassword(
        int $actorUserId,
        int $targetUserId,
        string $newPassword,
        bool $restrictToEmployeeAccount,
    ): void {
        if ($restrictToEmployeeAccount && !$this->isEmployeeAccount($targetUserId)) {
            throw new ForbiddenException('Target account is not an employee account.');
        }

        $old = $this->getAccountSnapshot($targetUserId);

        $hash = password_hash($newPassword, PASSWORD_BCRYPT);
        $stmt = $this->pdo->prepare('UPDATE users SET password_hash = :hash WHERE id = :id');
        $stmt->execute([
            ':hash' => $hash,
            ':id' => $targetUserId,
        ]);

        $new = $this->getAccountSnapshot($targetUserId);

        $this->appendAudit(
            $actorUserId,
            $targetUserId,
            'coordination_set_initial_password',
            'Initial password set',
            $old,
            $new,
        );
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
        $allowed = [
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

        $updates = [];
        $params = [':user_id' => $userId];

        foreach ($allowed as $key) {
            if (!array_key_exists($key, $fields)) {
                continue;
            }

            $updates[] = $key . ' = :' . $key;
            $params[':' . $key] = $fields[$key];
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
     * @param array<string, mixed> $old
     * @param array<string, mixed> $new
     */
    private function appendAudit(
        int $actorUserId,
        int $targetUserId,
        string $action,
        ?string $reason,
        array $old,
        array $new,
    ): void {
        $stmt = $this->pdo->prepare(
            'INSERT INTO employee_account_audit_log
                 (target_user_id, actor_user_id, action, reason, old_json, new_json, created_at)
             VALUES
                 (:target, :actor, :action, :reason, :old_json, :new_json, :created_at)'
        );

        $stmt->execute([
            ':target' => $targetUserId,
            ':actor' => $actorUserId,
            ':action' => $action,
            ':reason' => $reason,
            ':old_json' => json_encode($old, JSON_THROW_ON_ERROR),
            ':new_json' => json_encode($new, JSON_THROW_ON_ERROR),
            ':created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
    }
}
