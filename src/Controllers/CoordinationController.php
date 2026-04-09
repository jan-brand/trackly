<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Db\Db;
use App\Http\BadRequestException;
use App\Http\Response;
use App\Security\Auth;
use App\Security\Csrf;
use App\Security\Guard;
use App\Support\Flash;
use PDO;

/**
 * Coordination queue actions.
 *
 * Routes:
 *   POST /coordination/time-entries/:id/request-clarification → requestClarification()
 */
final class CoordinationController
{
    // -------------------------------------------------------------------------
    // POST /coordination/time-entries/:id/request-clarification
    // -------------------------------------------------------------------------

    public function requestClarification(): Response
    {
        Csrf::verifyOrFail();
        Guard::requireRole(['coordination', 'admin']);

        $id           = $this->routeId();
        $questionText = trim($_POST['question_text'] ?? '');

        if (mb_strlen($questionText) < 5) {
            throw new BadRequestException('question_text must be at least 5 characters.');
        }

        $actorUserId = (int) Auth::userId();
        $pdo         = Db::pdo();

        $pdo->beginTransaction();

        try {
            $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

            // Load time_entry FOR UPDATE (locking hint; skipped for SQLite in tests)
            $forUpdate = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
            $stmt = $pdo->prepare('SELECT * FROM time_entries WHERE id = :id' . $forUpdate);
            $stmt->execute([':id' => $id]);
            $entry = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($entry === false) {
                throw new \App\Http\ForbiddenException('Entry not found.');
            }

            // Insert clarification (status = open)
            $pdo->prepare(
                'INSERT INTO clarifications
                     (time_entry_id, asked_by_user_id, question_text, status, created_at)
                 VALUES
                     (:time_entry_id, :asked_by_user_id, :question_text, :status, :created_at)'
            )->execute([
                ':time_entry_id'    => $id,
                ':asked_by_user_id' => $actorUserId,
                ':question_text'    => $questionText,
                ':status'           => 'open',
                ':created_at'       => $now,
            ]);

            // Set time_entry.status = in_clarification
            $pdo->prepare(
                'UPDATE time_entries SET status = :status, updated_at = :updated_at WHERE id = :id'
            )->execute([
                ':status'     => 'in_clarification',
                ':updated_at' => $now,
                ':id'         => $id,
            ]);

            // Insert time_entry_audit_log
            $newRow = $pdo->prepare('SELECT * FROM time_entries WHERE id = :id');
            $newRow->execute([':id' => $id]);
            $newEntry = $newRow->fetch(PDO::FETCH_ASSOC);

            $pdo->prepare(
                'INSERT INTO time_entry_audit_log
                     (time_entry_id, actor_user_id, action, reason, old_json, new_json, created_at)
                 VALUES
                     (:time_entry_id, :actor_user_id, :action, :reason, :old_json, :new_json, :created_at)'
            )->execute([
                ':time_entry_id' => $id,
                ':actor_user_id' => $actorUserId,
                ':action'        => 'request_clarification',
                ':reason'        => $questionText,
                ':old_json'      => json_encode($entry, JSON_THROW_ON_ERROR),
                ':new_json'      => json_encode($newEntry, JSON_THROW_ON_ERROR),
                ':created_at'    => $now,
            ]);

            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        Flash::addSuccess('Rückfrage wurde gestellt.');

        return new Response(303, ['Location' => '/time-entries/' . $id], '');
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
