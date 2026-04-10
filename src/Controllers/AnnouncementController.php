<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Db\Db;
use App\Domain\Announcement\AnnouncementService;
use App\Domain\Announcement\AnnouncementValidationException;
use App\Domain\Announcement\AnnouncementValidator;
use App\Domain\Settings\Settings;
use App\Domain\Settings\SettingsRegistry;
use App\Http\Response;
use App\Security\Auth;
use App\Security\Csrf;
use App\Security\Guard;
use App\Support\Flash;
use PDO;

/**
 * Handles SSR screens for employee announcement self-service.
 *
 * Routes:
 *   GET  /announcements          → index()
 *   GET  /announcements/new      → newForm()
 *   POST /announcements          → create()
 *   GET  /announcements/:id      → show()
 *   POST /announcements/:id      → update()
 */
final class AnnouncementController
{
    private const STATUS_LABELS = [
        'pending_approval' => 'Zur Prüfung',
        'approved'         => 'Freigegeben',
        'rejected'         => 'Abgelehnt',
    ];

    // -------------------------------------------------------------------------
    // GET /announcements
    // -------------------------------------------------------------------------

    public function index(): Response
    {
        Guard::requireLogin();

        $userId = (int) Auth::userId();
        $pdo    = Db::pdo();

        $stmt = $pdo->prepare(
            'SELECT * FROM announcements WHERE user_id = :uid ORDER BY date_local DESC, planned_start_at DESC'
        );
        $stmt->execute([':uid' => $userId]);
        $announcements = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $body = renderView('announcements/index', [
            'title'         => 'Meine Ankündigungen – Trackly',
            'announcements' => $announcements,
            'statusLabels'  => self::STATUS_LABELS,
        ]);

        return new Response(200, ['Content-Type' => 'text/html; charset=utf-8'], $body);
    }

    // -------------------------------------------------------------------------
    // GET /announcements/new
    // -------------------------------------------------------------------------

    public function newForm(): Response
    {
        Guard::requireLogin();

        $body = renderView('announcements/new', [
            'title'  => 'Neue Ankündigung – Trackly',
            'errors' => [],
            'old'    => [],
        ]);

        return new Response(200, ['Content-Type' => 'text/html; charset=utf-8'], $body);
    }

    // -------------------------------------------------------------------------
    // POST /announcements
    // -------------------------------------------------------------------------

    public function create(): Response
    {
        Csrf::verifyOrFail();
        Guard::requireLogin();

        $userId = (int) Auth::userId();
        $input  = $this->extractInput();

        try {
            $derived = $this->makeValidator()->validate($input);
            $id      = $this->makeService()->create($userId, $derived);

            Flash::addSuccess('Ankündigung erfolgreich erstellt.');

            return new Response(303, ['Location' => '/announcements/' . $id], '');
        } catch (AnnouncementValidationException $e) {
            $body = renderView('announcements/new', [
                'title'  => 'Neue Ankündigung – Trackly',
                'errors' => $e->errors,
                'old'    => $input,
            ]);
            return new Response(422, ['Content-Type' => 'text/html; charset=utf-8'], $body);
        }
    }

    // -------------------------------------------------------------------------
    // GET /announcements/:id
    // -------------------------------------------------------------------------

    public function show(): Response
    {
        Guard::requireLogin();

        $id           = $this->routeId();
        $announcement = $this->fetchAnnouncement($id);

        $body = renderView('announcements/show', [
            'title'         => 'Ankündigung – Trackly',
            'announcement'  => $announcement,
            'statusLabels'  => self::STATUS_LABELS,
            'errors'        => [],
            'old'           => [],
        ]);

        return new Response(200, ['Content-Type' => 'text/html; charset=utf-8'], $body);
    }

    // -------------------------------------------------------------------------
    // POST /announcements/:id
    // -------------------------------------------------------------------------

    public function update(): Response
    {
        Csrf::verifyOrFail();
        Guard::requireLogin();

        $id           = $this->routeId();
        $announcement = $this->fetchAnnouncement($id);

        $input = $this->extractInput();

        try {
            $derived = $this->makeValidator()->validate($input);
            $this->makeService()->update((int) Auth::userId(), $id, $derived);

            Flash::addSuccess('Ankündigung erfolgreich aktualisiert.');

            return new Response(303, ['Location' => '/announcements/' . $id], '');
        } catch (AnnouncementValidationException $e) {
            $body = renderView('announcements/show', [
                'title'         => 'Ankündigung – Trackly',
                'announcement'  => $announcement,
                'statusLabels'  => self::STATUS_LABELS,
                'errors'        => $e->errors,
                'old'           => $input,
            ]);
            return new Response(422, ['Content-Type' => 'text/html; charset=utf-8'], $body);
        }
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
     * Fetch an announcement by ID and enforce ownership (employee sees only own).
     *
     * @return array<string, mixed>
     */
    private function fetchAnnouncement(int $id): array
    {
        $pdo  = Db::pdo();
        $stmt = $pdo->prepare('SELECT * FROM announcements WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $announcement = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($announcement === false) {
            throw new \App\Http\ForbiddenException('Announcement not found.');
        }

        Guard::requireOwnership((int) $announcement['user_id']);

        return $announcement;
    }

    /**
     * Extract and sanitise POST input fields for the announcement form.
     *
     * @return array<string, mixed>
     */
    private function extractInput(): array
    {
        return [
            'date'               => trim($_POST['date']               ?? ''),
            'planned_start_time' => trim($_POST['planned_start_time'] ?? ''),
            'planned_end_time'   => trim($_POST['planned_end_time']   ?? ''),
            'break_minutes'      => $_POST['break_minutes']           ?? '',
            'reason'             => trim($_POST['reason']             ?? ''),
        ];
    }

    private function makeValidator(): AnnouncementValidator
    {
        $registry = new SettingsRegistry();
        $settings = new Settings(Db::pdo(), $registry);
        return new AnnouncementValidator($settings->all());
    }

    private function makeService(): AnnouncementService
    {
        return new AnnouncementService(Db::pdo());
    }
}
