<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Db\Db;
use App\Domain\Settings\Settings;
use App\Domain\Settings\SettingsRegistry;
use App\Domain\Time\RuleEngine;
use App\Domain\Time\TimeEntryService;
use App\Http\Response;
use App\Security\Auth;
use App\Security\Csrf;
use App\Security\Guard;
use App\Support\Flash;
use PDO;

/**
 * Handles timer actions for employee/coordinator/admin self-service.
 *
 * Routes:
 *   GET  /timer          → index()
 *   POST /timer/start    → start()
 *   POST /timer/pause    → pause()
 *   POST /timer/resume   → resume()
 *   POST /timer/stop     → stop()
 */
final class TimerController
{
    /** Roles allowed to use the timer. */
    private const TIMER_ROLES = ['employee', 'coordination', 'admin'];
    // -------------------------------------------------------------------------
    // GET /timer
    // -------------------------------------------------------------------------

    public function index(): Response
    {
        Guard::requireRole(self::TIMER_ROLES);

        $userId = (int) Auth::userId();
        $pdo    = Db::pdo();

        $stmt = $pdo->prepare(
            "SELECT * FROM timer_sessions
              WHERE user_id = :uid AND status IN ('running', 'paused')
              ORDER BY started_at DESC
              LIMIT 1"
        );
        $stmt->execute([':uid' => $userId]);
        $session = $stmt->fetch() ?: null;

        $body = renderView('timer/show', [
            'title'         => 'Timer – Trackly',
            'timerSession'  => $session,
        ]);

        return new Response(200, ['Content-Type' => 'text/html; charset=utf-8'], $body);
    }

    // -------------------------------------------------------------------------
    // POST /timer/start
    // -------------------------------------------------------------------------

    public function start(): Response
    {
        Guard::requireRole(self::TIMER_ROLES);
        Csrf::verifyOrFail();

        $userId = (int) Auth::userId();
        $pdo    = Db::pdo();

        $pdo->beginTransaction();
        try {
            $existing = $this->lockActiveSession($pdo, $userId);

            if ($existing !== null) {
                $pdo->rollBack();
                Flash::addSuccess('Timer läuft bereits.');
                return new Response(303, ['Location' => '/timer'], '');
            }

            $pdo->prepare(
                "INSERT INTO timer_sessions (user_id, status, started_at)
                 VALUES (:uid, 'running', CURRENT_TIMESTAMP)"
            )->execute([':uid' => $userId]);

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        Flash::addSuccess('Timer gestartet.');
        return new Response(303, ['Location' => '/timer'], '');
    }

    // -------------------------------------------------------------------------
    // POST /timer/pause
    // -------------------------------------------------------------------------

    public function pause(): Response
    {
        Guard::requireRole(self::TIMER_ROLES);
        Csrf::verifyOrFail();

        $userId = (int) Auth::userId();
        $pdo    = Db::pdo();

        $pdo->beginTransaction();
        try {
            $existing = $this->lockActiveSession($pdo, $userId);

            if ($existing === null || $existing['status'] !== 'running') {
                $pdo->rollBack();
                return new Response(303, ['Location' => '/timer'], '');
            }

            $pdo->prepare(
                "UPDATE timer_sessions
                    SET status = 'paused', paused_at = CURRENT_TIMESTAMP
                  WHERE id = :id"
            )->execute([':id' => $existing['id']]);

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        Flash::addSuccess('Timer pausiert.');
        return new Response(303, ['Location' => '/timer'], '');
    }

    // -------------------------------------------------------------------------
    // POST /timer/resume
    // -------------------------------------------------------------------------

    public function resume(): Response
    {
        Guard::requireRole(self::TIMER_ROLES);
        Csrf::verifyOrFail();

        $userId = (int) Auth::userId();
        $pdo    = Db::pdo();

        $pdo->beginTransaction();
        try {
            $existing = $this->lockActiveSession($pdo, $userId);

            if ($existing === null || $existing['status'] !== 'paused') {
                $pdo->rollBack();
                return new Response(303, ['Location' => '/timer'], '');
            }

            $pausedAt    = $existing['paused_at'] ?? null;
            $pausedSecs  = ($pausedAt !== null)
                ? max(0, time() - (int) strtotime($pausedAt))
                : 0;

            $pdo->prepare(
                "UPDATE timer_sessions
                    SET status = 'running',
                        paused_at = NULL,
                        total_pause_seconds = total_pause_seconds + :secs
                  WHERE id = :id"
            )->execute([':secs' => $pausedSecs, ':id' => $existing['id']]);

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        Flash::addSuccess('Timer fortgesetzt.');
        return new Response(303, ['Location' => '/timer'], '');
    }

    // -------------------------------------------------------------------------
    // POST /timer/stop
    // -------------------------------------------------------------------------

    public function stop(): Response
    {
        Guard::requireRole(self::TIMER_ROLES);
        Csrf::verifyOrFail();

        $userId      = (int) Auth::userId();
        $service     = $this->makeService();
        $timeEntryId = $service->stopTimer($userId);

        if ($timeEntryId === null) {
            return new Response(303, ['Location' => '/timer'], '');
        }

        Flash::addSuccess('Timer gestoppt.');
        return new Response(303, ['Location' => '/time-entries/' . $timeEntryId], '');
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

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

    /**
     * Lock the active (running or paused) timer session for the given user.
     * Uses SELECT ... FOR UPDATE on MySQL; plain SELECT on SQLite.
     *
     * @return array<string, mixed>|null
     */
    private function lockActiveSession(PDO $pdo, int $userId): ?array
    {
        $forUpdate = ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') ? ' FOR UPDATE' : '';

        $stmt = $pdo->prepare(
            "SELECT * FROM timer_sessions
              WHERE user_id = :uid AND status IN ('running', 'paused')
              ORDER BY started_at DESC
              LIMIT 1" . $forUpdate
        );
        $stmt->execute([':uid' => $userId]);

        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }
}
