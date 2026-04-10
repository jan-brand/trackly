<?php
declare(strict_types=1);

/** @var list<array<string, mixed>> $announcements  Rows from announcements */
/** @var array<string, string>      $statusLabels   status → user-facing label */

$title = $title ?? 'Meine Ankündigungen – Trackly';

$esc = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

$fmtDt = static function (string $dt): string {
    try {
        return (new DateTimeImmutable($dt))->format('d.m.Y H:i');
    } catch (Throwable) {
        return $dt;
    }
};
?>
<div class="l-section">
    <div class="l-wrapper">
        <div class="u-flex u-flex-between u-mb-4">
            <h1>Meine Ankündigungen</h1>
            <a class="c-btn c-btn--primary c-btn--sm" href="/announcements/new">+ Neue Ankündigung</a>
        </div>

        <?php if (empty($announcements)): ?>
        <p class="u-text-muted">Noch keine Ankündigungen vorhanden.</p>
        <?php else: ?>
        <table class="c-table">
            <thead>
                <tr>
                    <th>Datum</th>
                    <th>Beginn (geplant)</th>
                    <th>Ende (geplant)</th>
                    <th>Pause</th>
                    <th>Netto</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($announcements as $a): ?>
                <tr>
                    <td><?= $esc($a['date_local']) ?></td>
                    <td><?= $esc($fmtDt((string) $a['planned_start_at'])) ?></td>
                    <td><?= $esc($fmtDt((string) $a['planned_end_at'])) ?></td>
                    <td><?= $esc($a['break_minutes']) ?> min</td>
                    <td><?= $esc($a['net_minutes']) ?> min</td>
                    <td>
                        <span class="c-badge c-badge--<?= $esc($a['status']) ?>">
                            <?= $esc($statusLabels[$a['status']] ?? $a['status']) ?>
                        </span>
                    </td>
                    <td>
                        <a class="c-btn c-btn--secondary c-btn--sm" href="/announcements/<?= $esc($a['id']) ?>">
                            Details
                        </a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>
