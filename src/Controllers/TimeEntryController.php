<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Db\Db;
use App\Domain\Settings\Settings;
use App\Domain\Settings\SettingsRegistry;
use App\Domain\Time\RuleEngine;
use App\Domain\Time\TimeEntryService;
use App\Domain\Time\TimeEntryValidationException;
use App\Domain\Time\TimeEntryValidator;
use App\Http\BadRequestException;
use App\Http\Response;
use App\Security\Auth;
use App\Security\Csrf;
use App\Security\Guard;
use App\Support\Flash;
use PDO;

/**
 * Handles SSR screens for manual time entries (employee self-service).
 *
 * Routes:
 *   GET  /time-entries               → index()
 *   GET  /time-entries/new           → newForm()
 *   POST /time-entries               → create()
 *   GET  /time-entries/:id           → show()
 *   POST /time-entries/:id           → update()
 *   POST /time-entries/:id/cancel    → cancel()
 */
final class TimeEntryController
{
    /** User-facing messages for each flag key. */
    private const FLAG_MESSAGES = [
        'overlap'         => 'Dieser Eintrag überschneidet sich mit einem anderen Eintrag.',
        'shift_too_long'  => 'Die Schichtdauer überschreitet das erlaubte Maximum.',
        'break_too_short' => 'Die Pausenzeit entspricht nicht den gesetzlichen Mindestanforderungen.',
    ];

    /** Status badge labels. */
    private const STATUS_LABELS = [
        'approved'          => 'Freigegeben',
        'pending_approval'  => 'Zur Prüfung',
        'in_clarification'  => 'Rückfrage offen',
        'cancelled'         => 'Storniert',
    ];

    // -------------------------------------------------------------------------
    // GET /time-entries
    // -------------------------------------------------------------------------

    public function index(): Response
    {
        Guard::requireLogin();

        $userId = (int) Auth::userId();
        $pdo    = Db::pdo();

        $stmt = $pdo->prepare(
            "SELECT * FROM time_entries
              WHERE user_id = :uid
              ORDER BY start_at DESC"
        );
        $stmt->execute([':uid' => $userId]);
        $entries = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmtAnn = $pdo->prepare(
            'SELECT * FROM announcements WHERE user_id = :uid ORDER BY date_local DESC, planned_start_at DESC'
        );
        $stmtAnn->execute([':uid' => $userId]);
        $announcements = $stmtAnn->fetchAll(PDO::FETCH_ASSOC);

        $body = renderView('time_entries/index', [
            'title'                => 'Meine Zeiteinträge – Trackly',
            'entries'              => $entries,
            'statusLabels'         => self::STATUS_LABELS,
            'announcements'        => $announcements,
            'announcementStatuses' => [
                'pending_approval' => 'Zur Prüfung',
                'approved'         => 'Freigegeben',
                'rejected'         => 'Abgelehnt',
            ],
        ]);

        return new Response(200, ['Content-Type' => 'text/html; charset=utf-8'], $body);
    }

    // -------------------------------------------------------------------------
    // GET /time-entries/new
    // -------------------------------------------------------------------------

    public function newForm(): Response
    {
        Guard::requireLogin();

        $body = renderView('time_entries/new', [
            'title'  => 'Neuer Zeiteintrag – Trackly',
            'errors' => [],
            'old'    => [],
        ]);

        return new Response(200, ['Content-Type' => 'text/html; charset=utf-8'], $body);
    }

    // -------------------------------------------------------------------------
    // POST /time-entries
    // -------------------------------------------------------------------------

    public function create(): Response
    {
        Csrf::verifyOrFail();
        Guard::requireLogin();

        $userId   = (int) Auth::userId();
        $input    = $this->extractInput();

        try {
            $validator = $this->makeValidator();
            $derived   = $validator->validate($input);
            $derived['reason'] = $input['reason'] ?? '';

            $service = $this->makeService();
            $id      = $service->createManual($userId, $userId, $derived);

            Flash::addSuccess('Zeiteintrag erfolgreich erstellt.');

            return new Response(303, ['Location' => '/time-entries/' . $id], '');
        } catch (TimeEntryValidationException $e) {
            $body = renderView('time_entries/new', [
                'title'  => 'Neuer Zeiteintrag – Trackly',
                'errors' => $e->errors,
                'old'    => $input,
            ]);
            return new Response(422, ['Content-Type' => 'text/html; charset=utf-8'], $body);
        }
    }

    // -------------------------------------------------------------------------
    // GET /time-entries/:id
    // -------------------------------------------------------------------------

    public function show(): Response
    {
        Guard::requireLogin();

        $id    = $this->routeId();
        $entry = $this->fetchEntry($id);

        $flags = $this->fetchFlags($id);

        $body = renderView('time_entries/show', [
            'title'        => 'Zeiteintrag – Trackly',
            'entry'        => $entry,
            'flags'        => $flags,
            'flagMessages' => self::FLAG_MESSAGES,
            'statusLabels' => self::STATUS_LABELS,
            'errors'       => [],
            'old'          => [],
        ]);

        return new Response(200, ['Content-Type' => 'text/html; charset=utf-8'], $body);
    }

    // -------------------------------------------------------------------------
    // POST /time-entries/:id
    // -------------------------------------------------------------------------

    public function update(): Response
    {
        Csrf::verifyOrFail();
        Guard::requireLogin();

        $id    = $this->routeId();
        $entry = $this->fetchEntry($id);

        // Disallow mutations on cancelled entries
        if ($entry['status'] === 'cancelled') {
            throw new BadRequestException('Cancelled entries cannot be modified.');
        }

        $input = $this->extractInput();

        try {
            $validator = $this->makeValidator();
            $derived   = $validator->validate($input);
            $derived['reason'] = $input['reason'] ?? '';

            $service = $this->makeService();
            $service->update((int) Auth::userId(), $id, $derived);

            Flash::addSuccess('Zeiteintrag erfolgreich aktualisiert.');

            return new Response(303, ['Location' => '/time-entries/' . $id], '');
        } catch (TimeEntryValidationException $e) {
            $flags = $this->fetchFlags($id);

            $body = renderView('time_entries/show', [
                'title'        => 'Zeiteintrag – Trackly',
                'entry'        => $entry,
                'flags'        => $flags,
                'flagMessages' => self::FLAG_MESSAGES,
                'statusLabels' => self::STATUS_LABELS,
                'errors'       => $e->errors,
                'old'          => $input,
            ]);
            return new Response(422, ['Content-Type' => 'text/html; charset=utf-8'], $body);
        }
    }

    // -------------------------------------------------------------------------
    // POST /time-entries/:id/cancel
    // -------------------------------------------------------------------------

    public function cancel(): Response
    {
        Csrf::verifyOrFail();
        Guard::requireLogin();

        $id    = $this->routeId();
        $entry = $this->fetchEntry($id);

        // Disallow re-cancelling
        if ($entry['status'] === 'cancelled') {
            throw new BadRequestException('Entry is already cancelled.');
        }

        $reason = trim($_POST['reason'] ?? '');
        if (mb_strlen($reason) < 3) {
            $flags = $this->fetchFlags($id);

            $body = renderView('time_entries/show', [
                'title'        => 'Zeiteintrag – Trackly',
                'entry'        => $entry,
                'flags'        => $flags,
                'flagMessages' => self::FLAG_MESSAGES,
                'statusLabels' => self::STATUS_LABELS,
                'errors'       => ['reason' => ['Begründung muss mindestens 3 Zeichen lang sein.']],
                'old'          => $_POST,
            ]);
            return new Response(422, ['Content-Type' => 'text/html; charset=utf-8'], $body);
        }

        $service = $this->makeService();
        $service->cancel((int) Auth::userId(), $id, $reason);

        Flash::addSuccess('Zeiteintrag storniert.');

        return new Response(303, ['Location' => '/time-entries'], '');
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
     * Fetch an entry by ID and enforce ownership.
     *
     * @return array<string, mixed>
     */
    private function fetchEntry(int $id): array
    {
        $pdo  = Db::pdo();
        $stmt = $pdo->prepare('SELECT * FROM time_entries WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $entry = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($entry === false) {
            // Treat not-found as forbidden so we don't leak existence
            throw new \App\Http\ForbiddenException('Entry not found.');
        }

        Guard::requireOwnership((int) $entry['user_id']);

        return $entry;
    }

    /**
     * Load flags for an entry, ordered by sort_index.
     *
     * @return list<array<string, mixed>>
     */
    private function fetchFlags(int $entryId): array
    {
        $pdo  = Db::pdo();
        $stmt = $pdo->prepare(
            'SELECT flag_key, flag_value, sort_index
               FROM time_entry_flags
              WHERE time_entry_id = :id
              ORDER BY sort_index ASC'
        );
        $stmt->execute([':id' => $entryId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Extract and sanitise the POST input fields for a time entry form.
     *
     * @return array<string, mixed>
     */
    private function extractInput(): array
    {
        return [
            'date'          => trim($_POST['date']          ?? ''),
            'start_time'    => trim($_POST['start_time']    ?? ''),
            'end_time'      => trim($_POST['end_time']      ?? ''),
            'break_minutes' => $_POST['break_minutes']      ?? '',
            'reason'        => trim($_POST['reason']        ?? ''),
        ];
    }

    private function makeValidator(): TimeEntryValidator
    {
        $registry = new SettingsRegistry();
        $settings = new Settings(Db::pdo(), $registry);
        return new TimeEntryValidator($settings->all());
    }

    private function makeService(): TimeEntryService
    {
        $registry = new SettingsRegistry();
        $settings = new Settings(Db::pdo(), $registry);
        return new TimeEntryService(
            Db::pdo(),
            new RuleEngine(),
            $settings->all(),
        );
    }
}
