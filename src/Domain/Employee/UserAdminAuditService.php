<?php

declare(strict_types=1);

namespace App\Domain\Employee;

use PDO;

final class UserAdminAuditService
{
    public function __construct(private readonly PDO $pdo) {}

    /**
     * @param array<string, mixed> $oldState
     * @param array<string, mixed> $newState
     */
    public function record(
        int $actorUserId,
        int $targetUserId,
        string $action,
        ?string $reason,
        array $oldState,
        array $newState,
    ): void {
        $diff = $this->buildDiff($oldState, $newState);

        $stmt = $this->pdo->prepare(
            'INSERT INTO user_admin_audit_log
                 (actor_user_id, target_user_id, action, reason, diff_json, created_at)
             VALUES
                 (:actor, :target, :action, :reason, :diff_json, :created_at)'
        );

        $stmt->execute([
            ':actor' => $actorUserId,
            ':target' => $targetUserId,
            ':action' => $action,
            ':reason' => $reason,
            ':diff_json' => json_encode($diff, JSON_THROW_ON_ERROR),
            ':created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * @return array{rows: list<array<string, mixed>>, has_more: bool}
     */
    public function listForTarget(int $targetUserId, int $limit, int $offset): array
    {
        $limit = max(1, $limit);
        $offset = max(0, $offset);

        $stmt = $this->pdo->prepare(
            'SELECT
                l.id,
                l.actor_user_id,
                l.target_user_id,
                l.action,
                l.reason,
                l.diff_json,
                l.created_at,
                a.email AS actor_email,
                ep.display_name AS actor_display_name
             FROM user_admin_audit_log l
             LEFT JOIN users a ON a.id = l.actor_user_id
             LEFT JOIN employee_profiles ep ON ep.user_id = a.id
             WHERE l.target_user_id = :target
             ORDER BY l.created_at DESC, l.id DESC
             LIMIT :limit_plus_one OFFSET :offset'
        );
        $stmt->bindValue(':target', $targetUserId, PDO::PARAM_INT);
        $stmt->bindValue(':limit_plus_one', $limit + 1, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $hasMore = count($rows) > $limit;
        if ($hasMore) {
            array_pop($rows);
        }

        foreach ($rows as &$row) {
            $row['diff'] = $this->decodeDiff((string) ($row['diff_json'] ?? '{}'));
        }
        unset($row);

        return ['rows' => $rows, 'has_more' => $hasMore];
    }

    /**
     * @param array<string, mixed> $oldState
     * @param array<string, mixed> $newState
     * @return array<string, mixed>
     */
    private function buildDiff(array $oldState, array $newState): array
    {
        $keys = array_values(array_unique(array_merge(array_keys($oldState), array_keys($newState))));
        sort($keys);

        $diff = [];

        foreach ($keys as $key) {
            $oldExists = array_key_exists($key, $oldState);
            $newExists = array_key_exists($key, $newState);

            $oldValue = $oldExists ? $oldState[$key] : null;
            $newValue = $newExists ? $newState[$key] : null;

            if (!$oldExists && !$newExists) {
                continue;
            }

            if ($oldValue === $newValue) {
                continue;
            }

            if ($this->isPasswordLikeField($key)) {
                $diff[$key] = ['changed' => true];
                continue;
            }

            $diff[$key] = [
                'old' => $oldValue,
                'new' => $newValue,
            ];
        }

        return $diff;
    }

    private function isPasswordLikeField(string $field): bool
    {
        $normalized = strtolower($field);
        return str_contains($normalized, 'password') || str_contains($normalized, 'hash');
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeDiff(string $json): array
    {
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            return is_array($decoded) ? $decoded : [];
        } catch (\Throwable) {
            return [];
        }
    }
}
