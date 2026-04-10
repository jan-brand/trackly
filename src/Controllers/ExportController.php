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
 * Handles the Export screen and export downloads.
 *
 * Routes:
 *   GET  /export          → index()
 *   POST /export/csv      → csv()
 *   POST /export/pdf      → pdf()
 */
final class ExportController
{
    /** Roles that may choose a scope other than 'self'. */
    private const ELEVATED_ROLES = ['coordination', 'treasurer', 'admin'];

    /** Whitelisted scope values for elevated roles. */
    private const ALLOWED_SCOPES = ['self', 'single_user', 'all_users'];

    /** CSV header row (AC4). */
    private const CSV_HEADER = ['Datum', 'Mitarbeiter', 'Beginn', 'Ende', 'Pause (Min)', 'Netto (Min)'];

    // -------------------------------------------------------------------------
    // GET /export
    // -------------------------------------------------------------------------

    public function index(): Response
    {
        Guard::requireRole(array_merge(['employee'], self::ELEVATED_ROLES));

        $isElevated = Auth::hasAnyRole(self::ELEVATED_ROLES);
        $month      = $_GET['month'] ?? date('Y-m');

        $pdo   = Db::pdo();
        $users = [];

        if ($isElevated) {
            $stmt = $pdo->query(
                'SELECT u.id, ep.display_name, u.email
                 FROM users u
                 LEFT JOIN employee_profiles ep ON ep.user_id = u.id
                 WHERE u.is_active = 1
                 ORDER BY ep.display_name, u.email'
            );
            $users = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        }

        $body = renderView('export/index', [
            'title'      => 'Export – Trackly',
            'month'      => $month,
            'isElevated' => $isElevated,
            'users'      => $users,
        ]);

        return new Response(200, ['Content-Type' => 'text/html; charset=utf-8'], $body);
    }

    // -------------------------------------------------------------------------
    // POST /export/csv
    // -------------------------------------------------------------------------

    public function csv(): Response
    {
        Csrf::verifyOrFail();
        Guard::requireRole(array_merge(['employee'], self::ELEVATED_ROLES));

        $month        = trim($_POST['month'] ?? '');
        $rawScope     = trim($_POST['scope'] ?? 'self');
        $targetUserId = (int) ($_POST['target_user_id'] ?? 0) ?: null;

        [$month, $scope, $targetUserId] = $this->resolveParams($month, $rawScope, $targetUserId);

        $rows = $this->fetchRows($scope, $month, $targetUserId);

        $this->writeExportLog('csv', $scope, $targetUserId, $month, count($rows), 'ok');

        $csv = $this->buildCsv($rows);

        $filename = 'trackly_export_' . $month . '.csv';

        return new Response(200, [
            'Content-Type'        => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ], "\xEF\xBB\xBF" . $csv); // UTF-8 BOM for Excel
    }

    // -------------------------------------------------------------------------
    // POST /export/pdf
    // -------------------------------------------------------------------------

    public function pdf(): Response
    {
        Csrf::verifyOrFail();
        Guard::requireRole(array_merge(['employee'], self::ELEVATED_ROLES));

        $month        = trim($_POST['month'] ?? '');
        $rawScope     = trim($_POST['scope'] ?? 'self');
        $targetUserId = (int) ($_POST['target_user_id'] ?? 0) ?: null;

        [$month, $scope, $targetUserId] = $this->resolveParams($month, $rawScope, $targetUserId);

        $rows = $this->fetchRows($scope, $month, $targetUserId);

        if (count($rows) === 0) {
            $this->writeExportLog('pdf', $scope, $targetUserId, $month, 0, 'empty');
            Flash::addError('Keine Daten für den Export.');
            return new Response(422, ['Content-Type' => 'text/html; charset=utf-8'], renderView('errors/422'));
        }

        $wkhtmlPath = $_ENV['WKHTMLTOPDF_PATH'] ?? getenv('WKHTMLTOPDF_PATH') ?: '';

        if ($wkhtmlPath === '' || !is_executable($wkhtmlPath)) {
            error_log('[ExportController] wkhtmltopdf binary not found or not executable: ' . $wkhtmlPath);
            Flash::addError('PDF-Export nicht verfügbar (wkhtmltopdf fehlt).');
            return new Response(500, ['Content-Type' => 'text/html; charset=utf-8'], renderView('errors/500'));
        }

        $htmlContent = $this->renderPdfView([
            'month' => $month,
            'rows'  => $rows,
        ]);

        $tmpHtml = tempnam(sys_get_temp_dir(), 'trackly_pdf_html_');
        $tmpPdf  = tempnam(sys_get_temp_dir(), 'trackly_pdf_out_');

        if ($tmpHtml === false || $tmpPdf === false) {
            error_log('[ExportController] Failed to create temp files for PDF export.');
            Flash::addError('PDF-Export intern fehlgeschlagen.');
            return new Response(500, ['Content-Type' => 'text/html; charset=utf-8'], renderView('errors/500'));
        }

        try {
            file_put_contents($tmpHtml, $htmlContent);

            $cmd = [$wkhtmlPath, '--quiet', $tmpHtml, $tmpPdf];

            $proc = proc_open(
                $cmd,
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
            );

            if ($proc === false) {
                throw new \RuntimeException('proc_open failed');
            }

            fclose($pipes[0]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exitCode = proc_close($proc);

            if ($exitCode !== 0) {
                error_log('[ExportController] wkhtmltopdf exited with ' . $exitCode . ': ' . $stderr);
                Flash::addError('PDF-Generierung fehlgeschlagen.');
                return new Response(500, ['Content-Type' => 'text/html; charset=utf-8'], renderView('errors/500'));
            }

            $pdfContent = file_get_contents($tmpPdf);

            if ($pdfContent === false) {
                throw new \RuntimeException('Could not read generated PDF.');
            }

            $this->writeExportLog('pdf', $scope, $targetUserId, $month, count($rows), 'ok');

            $filename = 'trackly_export_' . $month . '.pdf';

            return new Response(200, [
                'Content-Type'        => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            ], $pdfContent);
        } finally {
            if (file_exists($tmpHtml)) {
                @unlink($tmpHtml);
            }
            if (file_exists($tmpPdf)) {
                @unlink($tmpPdf);
            }
        }
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Resolve and validate scope + month, enforcing RBAC.
     *
     * @param  string   $month
     * @param  string   $rawScope
     * @param  int|null $targetUserId
     * @return array{string, string, int|null}
     * @throws BadRequestException on invalid scope
     */
    private function resolveParams(string $month, string $rawScope, ?int $targetUserId): array
    {
        // Validate month format YYYY-MM
        if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
            throw new BadRequestException('Invalid month format.');
        }

        // RBAC scope enforcement
        if (!Auth::hasAnyRole(self::ELEVATED_ROLES)) {
            $scope        = 'self';
            $targetUserId = null;
        } else {
            if (!in_array($rawScope, self::ALLOWED_SCOPES, true)) {
                throw new BadRequestException('Unknown scope value.');
            }
            $scope = $rawScope;

            if ($scope !== 'single_user') {
                $targetUserId = null;
            }
        }

        return [$month, $scope, $targetUserId];
    }

    /**
     * Fetch approved time entries for the given scope/month.
     *
     * @return list<array<string, mixed>>
     */
    private function fetchRows(string $scope, string $month, ?int $targetUserId): array
    {
        $pdo = Db::pdo();

        $monthStart = $month . '-01';
        $monthEnd   = date('Y-m-t', strtotime($monthStart));

        $baseSelect = "
            SELECT
                te.date_local,
                COALESCE(ep.display_name, u.email) AS display_name,
                te.start_at,
                te.end_at,
                te.break_minutes,
                te.net_minutes
            FROM time_entries te
            JOIN users u ON u.id = te.user_id
            LEFT JOIN employee_profiles ep ON ep.user_id = te.user_id
            WHERE te.status = 'approved'
              AND te.date_local BETWEEN :month_start AND :month_end
        ";

        $params = [':month_start' => $monthStart, ':month_end' => $monthEnd];

        if ($scope === 'self') {
            $baseSelect .= ' AND te.user_id = :uid';
            $params[':uid'] = (int) Auth::userId();
        } elseif ($scope === 'single_user' && $targetUserId !== null) {
            $baseSelect .= ' AND te.user_id = :uid';
            $params[':uid'] = $targetUserId;
        }
        // all_users: no additional filter

        $baseSelect .= ' ORDER BY te.date_local, display_name, te.start_at';

        $stmt = $pdo->prepare($baseSelect);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Render the PDF HTML view directly (without layout wrapper).
     *
     * @param array<string, mixed> $data
     */
    private function renderPdfView(array $data): string
    {
        extract($data);
        ob_start();
        require dirname(__DIR__) . '/Views/export/pdf.php';
        return (string) ob_get_clean();
    }

    /**
     *
     * @param list<array<string, mixed>> $rows
     */
    private function buildCsv(array $rows): string
    {
        $lines = [];
        $lines[] = $this->csvLine(self::CSV_HEADER);

        foreach ($rows as $row) {
            $displayName = (string) ($row['display_name'] ?? '');

            // Formula injection mitigation (AC5)
            if ($displayName !== '' && in_array($displayName[0], ['=', '+', '-', '@'], true)) {
                $displayName = "'" . $displayName;
            }

            $startAt = $row['start_at'] ?? '';
            $endAt   = $row['end_at']   ?? '';

            // Format times as HH:MM if they are full datetimes
            if (strlen($startAt) > 5) {
                $startAt = substr($startAt, 11, 5);
            }
            if (strlen($endAt) > 5) {
                $endAt = substr($endAt, 11, 5);
            }

            $lines[] = $this->csvLine([
                $row['date_local']    ?? '',
                $displayName,
                $startAt,
                $endAt,
                (string) ($row['break_minutes'] ?? '0'),
                (string) ($row['net_minutes']   ?? '0'),
            ]);
        }

        return implode('', $lines);
    }

    /**
     * Encode one CSV line with `;` delimiter and CRLF terminator.
     *
     * @param string[] $fields
     */
    private function csvLine(array $fields): string
    {
        $escaped = array_map(static function (string $field): string {
            // Wrap in quotes if field contains delimiter, quote, or newline
            if (strpbrk($field, ";\"\r\n") !== false) {
                return '"' . str_replace('"', '""', $field) . '"';
            }
            return $field;
        }, $fields);

        return implode(';', $escaped) . "\r\n";
    }

    /**
     * Write one row to export_log.
     */
    private function writeExportLog(
        string $exportType,
        string $scope,
        ?int   $targetUserId,
        string $month,
        int    $rowCount,
        string $status,
    ): void {
        try {
            $pdo  = Db::pdo();
            $stmt = $pdo->prepare(
                'INSERT INTO export_log
                     (actor_user_id, export_type, scope, target_user_id, month, row_count, status)
                 VALUES
                     (:actor, :type, :scope, :target, :month, :rows, :status)'
            );
            $stmt->execute([
                ':actor'  => Auth::userId(),
                ':type'   => $exportType,
                ':scope'  => $scope,
                ':target' => $targetUserId,
                ':month'  => $month,
                ':rows'   => $rowCount,
                ':status' => $status,
            ]);
        } catch (\Throwable $e) {
            error_log('[ExportController] Failed to write export_log: ' . $e->getMessage());
        }
    }
}
