<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Db\Db;
use App\Domain\Announcement\AnnouncementService;
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
 *   GET  /coordination/queue                                   → queue()
 *   GET  /coordination/time-entries/:id                        → show()
 *   POST /coordination/time-entries/:id/approve                → approve()
 *   POST /coordination/time-entries/:id/request-clarification  → requestClarification()
 */
final class CoordinationController
{
    private const ALLOWED_PARAMS = ['tab', 'month', 'status', 'user_id', 'sort'];

    private const ALLOWED_TABS     = ['times', 'announcements'];
    private const ALLOWED_STATUSES = ['pending_approval', 'in_clarification', 'approved', 'rejected', 'all'];
    private const ALLOWED_SORTS    = ['oldest', 'newest', 'person_asc'];

    // -------------------------------------------------------------------------
    // GET /coordination/queue
    // -------------------------------------------------------------------------

    public function queue(): Response
    {
        Guard::requireRole(['coordination', 'admin']);

        // Parse query params from the request URI (compatible with CLI test harness)
        $query = [];
        parse_str(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_QUERY) ?? '', $query);

        // Whitelist query params – unknown keys ⇒ 400
        foreach (array_keys($query) as $key) {
            if (!in_array($key, self::ALLOWED_PARAMS, true)) {
                throw new BadRequestException('Unknown query parameter: ' . $key);
            }
        }

        // tab
        $tab = $query['tab'] ?? 'times';
        if (!in_array($tab, self::ALLOWED_TABS, true)) {
            throw new BadRequestException('Invalid tab value.');
        }

        // month (YYYY-MM), default = current month
        $defaultMonth = (new \DateTimeImmutable())->format('Y-m');
        $month        = $query['month'] ?? $defaultMonth;
        if (!preg_match('/^\d{4}-(?:0[1-9]|1[0-2])$/', (string) $month)) {
            throw new BadRequestException('Invalid month value.');
        }

        // status
        $status = $query['status'] ?? 'all';
        if (!in_array($status, self::ALLOWED_STATUSES, true)) {
            throw new BadRequestException('Invalid status value.');
        }

        // user_id (optional, must be a positive integer when present)
        $userId = null;
        if (isset($query['user_id'])) {
            $userId = filter_var($query['user_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($userId === false) {
                throw new BadRequestException('Invalid user_id value.');
            }
            $userId = (int) $userId;
        }

        // sort
        $sort = $query['sort'] ?? 'oldest';
        if (!in_array($sort, self::ALLOWED_SORTS, true)) {
            throw new BadRequestException('Invalid sort value.');
        }

        // tab=announcements – build read-model
        if ($tab === 'announcements') {
            $pdo = Db::pdo();

            $monthStart = $month . '-01';
            $monthEnd   = date('Y-m-t', strtotime($monthStart));

            $where  = ['a.date_local BETWEEN :month_start AND :month_end'];
            $params = [':month_start' => $monthStart, ':month_end' => $monthEnd];

            if ($status !== 'all') {
                $where[]           = 'a.status = :status';
                $params[':status'] = $status;
            }

            if ($userId !== null) {
                $where[]            = 'a.user_id = :user_id';
                $params[':user_id'] = $userId;
            }

            $orderBy = match ($sort) {
                'newest'     => 'a.planned_start_at DESC',
                'person_asc' => 'u.email ASC, a.planned_start_at ASC',
                default      => 'a.planned_start_at ASC',  // oldest
            };

            $whereClause = implode(' AND ', $where);

            $stmt = $pdo->prepare(
                "SELECT a.*, u.email AS user_email
                   FROM announcements a
                   JOIN users u ON u.id = a.user_id
                  WHERE {$whereClause}
                  ORDER BY {$orderBy}"
            );
            $stmt->execute($params);
            $entries = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $body = renderView('coordination/queue', [
                'title'   => 'Queue – Ankündigungen – Trackly',
                'heading' => 'Queue – Ankündigungen',
                'tab'     => 'announcements',
                'entries' => $entries,
                'month'   => $month,
                'status'  => $status,
                'sort'    => $sort,
                'userId'  => $userId,
            ]);
            return new Response(200, ['Content-Type' => 'text/html; charset=utf-8'], $body);
        }

        // tab=times – build read-model
        $pdo = Db::pdo();

        $monthStart = $month . '-01';
        $monthEnd   = date('Y-m-t', strtotime($monthStart));

        $where  = ['te.date_local BETWEEN :month_start AND :month_end'];
        $params = [':month_start' => $monthStart, ':month_end' => $monthEnd];

        if ($status !== 'all') {
            $where[]            = 'te.status = :status';
            $params[':status']  = $status;
        }

        if ($userId !== null) {
            $where[]            = 'te.user_id = :user_id';
            $params[':user_id'] = $userId;
        }

        $orderBy = match ($sort) {
            'newest'     => 'te.start_at DESC',
            'person_asc' => 'u.email ASC, te.start_at ASC',
            default      => 'te.start_at ASC',  // oldest
        };

        $whereClause = implode(' AND ', $where);

        $stmt = $pdo->prepare(
            "SELECT te.*, u.email AS user_email
               FROM time_entries te
               JOIN users u ON u.id = te.user_id
              WHERE {$whereClause}
              ORDER BY {$orderBy}"
        );
        $stmt->execute($params);
        $entries = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $body = renderView('coordination/queue', [
            'title'   => 'Koordinations-Queue – Trackly',
            'heading' => 'Queue – Zeiteinträge',
            'tab'     => 'times',
            'entries' => $entries,
            'month'   => $month,
            'status'  => $status,
            'sort'    => $sort,
            'userId'  => $userId,
        ]);

        return new Response(200, ['Content-Type' => 'text/html; charset=utf-8'], $body);
    }

    // -------------------------------------------------------------------------
    // GET /coordination/time-entries/:id
    // -------------------------------------------------------------------------

    public function show(): Response
    {
        Guard::requireRole(['coordination', 'admin']);

        $id  = $this->routeId();
        $pdo = Db::pdo();

        $stmt = $pdo->prepare('SELECT * FROM time_entries WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $entry = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($entry === false) {
            throw new \App\Http\ForbiddenException('Entry not found.');
        }

        // Fetch user display_name / email
        $stmt = $pdo->prepare('SELECT email FROM users WHERE id = :id');
        $stmt->execute([':id' => $entry['user_id']]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        $displayName = $user['email'] ?? '–';

        // Flags (message_admin only)
        $stmt = $pdo->prepare(
            "SELECT * FROM time_entry_flags
              WHERE time_entry_id = :id AND flag_key = 'message_admin'
              ORDER BY sort_index ASC"
        );
        $stmt->execute([':id' => $id]);
        $flags = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Audit log (newest first)
        $stmt = $pdo->prepare(
            'SELECT * FROM time_entry_audit_log
              WHERE time_entry_id = :id
              ORDER BY created_at DESC, id DESC'
        );
        $stmt->execute([':id' => $id]);
        $auditLog = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Open clarifications
        $stmt = $pdo->prepare(
            "SELECT * FROM clarifications
              WHERE time_entry_id = :id AND status = 'open'
              ORDER BY created_at ASC"
        );
        $stmt->execute([':id' => $id]);
        $openClarifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $body = renderView('coordination/time_entries/show', [
            'title'              => 'Zeiteintrag – Koordination – Trackly',
            'entry'              => $entry,
            'displayName'        => $displayName,
            'flags'              => $flags,
            'auditLog'           => $auditLog,
            'openClarifications' => $openClarifications,
        ]);

        return new Response(200, ['Content-Type' => 'text/html; charset=utf-8'], $body);
    }

    // -------------------------------------------------------------------------
    // POST /coordination/time-entries/:id/approve
    // -------------------------------------------------------------------------

    public function approve(): Response
    {
        Csrf::verifyOrFail();
        Guard::requireRole(['coordination', 'admin']);

        $id          = $this->routeId();
        $actorUserId = (int) Auth::userId();
        $pdo         = Db::pdo();

        $pdo->beginTransaction();

        try {
            $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

            $forUpdate = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
            $stmt = $pdo->prepare('SELECT * FROM time_entries WHERE id = :id' . $forUpdate);
            $stmt->execute([':id' => $id]);
            $entry = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($entry === false) {
                throw new \App\Http\ForbiddenException('Entry not found.');
            }

            // Idempotent: already approved → success without new audit
            if ($entry['status'] === 'approved') {
                $pdo->commit();
                Flash::addSuccess('Zeiteintrag wurde freigegeben.');
                return new Response(303, ['Location' => '/coordination/queue'], '');
            }

            // Set approved
            $pdo->prepare(
                'UPDATE time_entries
                    SET status = :status,
                        approved_by_user_id = :approved_by,
                        approved_at = :approved_at,
                        updated_at = :updated_at
                  WHERE id = :id'
            )->execute([
                ':status'      => 'approved',
                ':approved_by' => $actorUserId,
                ':approved_at' => $now,
                ':updated_at'  => $now,
                ':id'          => $id,
            ]);

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
                ':action'        => 'approve',
                ':reason'        => 'Freigegeben',
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

        Flash::addSuccess('Zeiteintrag wurde freigegeben.');

        return new Response(303, ['Location' => '/coordination/queue'], '');
    }

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
    // POST /coordination/announcements/:id/approve
    // -------------------------------------------------------------------------

    public function approveAnnouncement(): Response
    {
        Csrf::verifyOrFail();
        Guard::requireRole(['coordination', 'admin']);

        $id          = $this->routeId();
        $actorUserId = (int) Auth::userId();

        $this->fetchAnnouncementOrFail($id);

        (new AnnouncementService(Db::pdo()))->approve($actorUserId, $id);

        Flash::addSuccess('Ankündigung wurde freigegeben.');

        return new Response(303, ['Location' => '/coordination/queue?tab=announcements'], '');
    }

    // -------------------------------------------------------------------------
    // POST /coordination/announcements/:id/reject
    // -------------------------------------------------------------------------

    public function rejectAnnouncement(): Response
    {
        Csrf::verifyOrFail();
        Guard::requireRole(['coordination', 'admin']);

        $id             = $this->routeId();
        $actorUserId    = (int) Auth::userId();
        $rejectionReason = trim($_POST['rejection_reason'] ?? '');

        if (mb_strlen($rejectionReason) < 3) {
            throw new BadRequestException('rejection_reason must be at least 3 characters.');
        }

        $this->fetchAnnouncementOrFail($id);

        (new AnnouncementService(Db::pdo()))->reject($actorUserId, $id, $rejectionReason);

        Flash::addSuccess('Ankündigung wurde abgelehnt.');

        return new Response(303, ['Location' => '/coordination/queue?tab=announcements'], '');
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

    /**
     * @return array<string, mixed>
     */
    private function fetchAnnouncementOrFail(int $id): array
    {
        $pdo  = Db::pdo();
        $stmt = $pdo->prepare('SELECT * FROM announcements WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            // Treat not-found as forbidden so we don't leak existence to employees.
            throw new \App\Http\ForbiddenException('Announcement not found.');
        }

        return $row;
    }
}
