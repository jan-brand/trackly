<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Db\Db;
use App\Http\BadRequestException;
use App\Http\ForbiddenException;
use App\Http\Response;
use App\Security\Auth;
use App\Security\Csrf;
use App\Security\Guard;
use App\Support\Flash;
use PDO;

/**
 * Employee / coordination clarification actions.
 *
 * Routes:
 *   GET  /clarifications            → index()
 *   POST /clarifications/:id/answer → answer()
 */
final class ClarificationController
{
    // -------------------------------------------------------------------------
    // GET /clarifications
    // -------------------------------------------------------------------------

    public function index(): Response
    {
        Guard::requireLogin();

        $pdo    = Db::pdo();
        $userId = (int) Auth::userId();
        $isCoordOrAdmin = Auth::hasAnyRole(['coordination', 'admin']);

        if ($isCoordOrAdmin) {
            $stmt = $pdo->query(
                'SELECT c.*, te.user_id AS entry_user_id
                   FROM clarifications c
                   JOIN time_entries te ON te.id = c.time_entry_id
                  ORDER BY c.created_at DESC'
            );
        } else {
            $stmt = $pdo->prepare(
                'SELECT c.*, te.user_id AS entry_user_id
                   FROM clarifications c
                   JOIN time_entries te ON te.id = c.time_entry_id
                  WHERE te.user_id = :uid
                  ORDER BY c.created_at DESC'
            );
            $stmt->execute([':uid' => $userId]);
        }

        $clarifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $body = renderView('clarifications/index', [
            'title'          => 'Rückfragen – Trackly',
            'clarifications' => $clarifications,
        ]);

        return new Response(200, ['Content-Type' => 'text/html; charset=utf-8'], $body);
    }

    // -------------------------------------------------------------------------
    // POST /clarifications/:id/answer
    // -------------------------------------------------------------------------

    public function answer(): Response
    {
        Csrf::verifyOrFail();
        Guard::requireLogin();

        $id         = $this->routeId();
        $answerText = trim($_POST['answer_text'] ?? '');

        if (mb_strlen($answerText) < 2) {
            throw new BadRequestException('answer_text must be at least 2 characters.');
        }

        $actorUserId = (int) Auth::userId();
        $pdo         = Db::pdo();

        $pdo->beginTransaction();

        try {
            $now       = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
            $forUpdate = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';

            // Load clarification FOR UPDATE – must be open
            $stmt = $pdo->prepare('SELECT * FROM clarifications WHERE id = :id' . $forUpdate);
            $stmt->execute([':id' => $id]);
            $clarification = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($clarification === false) {
                throw new ForbiddenException('Clarification not found.');
            }

            if ($clarification['status'] !== 'open') {
                throw new BadRequestException('Clarification is already answered.');
            }

            // RBAC: employee may only answer if the time_entry belongs to them
            $timeEntryId = (int) $clarification['time_entry_id'];
            if (!Auth::hasAnyRole(['coordination', 'admin'])) {
                $entryStmt = $pdo->prepare('SELECT user_id FROM time_entries WHERE id = :id');
                $entryStmt->execute([':id' => $timeEntryId]);
                $entryUserId = $entryStmt->fetchColumn();
                if ((int) $entryUserId !== $actorUserId) {
                    throw new ForbiddenException('Access denied: not the owner of this entry.');
                }
            }

            // Update clarification as answered
            $pdo->prepare(
                'UPDATE clarifications
                    SET status               = :status,
                        answer_text          = :answer_text,
                        answered_by_user_id  = :answered_by,
                        answered_at          = :answered_at
                  WHERE id = :id'
            )->execute([
                ':status'      => 'answered',
                ':answer_text' => $answerText,
                ':answered_by' => $actorUserId,
                ':answered_at' => $now,
                ':id'          => $id,
            ]);

            // Load target time_entry FOR UPDATE
            $targetStmt = $pdo->prepare('SELECT * FROM time_entries WHERE id = :id' . $forUpdate);
            $targetStmt->execute([':id' => $timeEntryId]);
            $targetEntry = $targetStmt->fetch(PDO::FETCH_ASSOC);

            // Insert audit log
            $pdo->prepare(
                'INSERT INTO time_entry_audit_log
                     (time_entry_id, actor_user_id, action, reason, old_json, new_json, created_at)
                 VALUES
                     (:time_entry_id, :actor_user_id, :action, :reason, :old_json, :new_json, :created_at)'
            )->execute([
                ':time_entry_id' => $timeEntryId,
                ':actor_user_id' => $actorUserId,
                ':action'        => 'answer_clarification',
                ':reason'        => $answerText,
                ':old_json'      => json_encode($targetEntry, JSON_THROW_ON_ERROR),
                ':new_json'      => json_encode($targetEntry, JSON_THROW_ON_ERROR),
                ':created_at'    => $now,
            ]);

            // Count remaining open clarifications for the target entry
            $countStmt = $pdo->prepare(
                "SELECT COUNT(*) FROM clarifications
                  WHERE time_entry_id = :time_entry_id AND status = 'open'"
            );
            $countStmt->execute([':time_entry_id' => $timeEntryId]);
            $openCount = (int) $countStmt->fetchColumn();

            if ($openCount === 0) {
                $pdo->prepare(
                    'UPDATE time_entries SET status = :status, updated_at = :updated_at WHERE id = :id'
                )->execute([
                    ':status'     => 'pending_approval',
                    ':updated_at' => $now,
                    ':id'         => $timeEntryId,
                ]);
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        Flash::addSuccess('Antwort gesendet.');

        return new Response(303, ['Location' => '/clarifications'], '');
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function routeId(): int
    {
        $id = (int) ($_SERVER['ROUTE_PARAMS']['id'] ?? 0);
        if ($id <= 0) {
            throw new \RuntimeException('Invalid route parameter id.');
        }
        return $id;
    }
}
