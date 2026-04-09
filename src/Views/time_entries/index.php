<?php
declare(strict_types=1);

/** @var list<array<string, mixed>>     $entries      Rows from time_entries */
/** @var array<string, string>          $statusLabels Status → human-readable label */

$title = $title ?? 'Meine Zeiteinträge – Trackly';

$esc = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

$formatDateTime = static function (string $dt): string {
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
            <h1>Meine Zeiteinträge</h1>
            <a class="c-btn c-btn--primary" href="/time-entries/new">Neuer Eintrag</a>
        </div>

        <?php if (empty($entries)): ?>
        <p class="u-text-muted">Noch keine Zeiteinträge vorhanden.</p>
        <?php else: ?>
        <table class="c-table">
            <thead>
            <tr>
                <th scope="col">Datum</th>
                <th scope="col">Beginn</th>
                <th scope="col">Ende</th>
                <th scope="col">Netto (min)</th>
                <th scope="col">Status</th>
                <th scope="col"></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($entries as $entry): ?>
            <tr>
                <td><?= $esc($entry['date_local']) ?></td>
                <td><?= $esc($formatDateTime($entry['start_at'])) ?></td>
                <td><?= $esc($formatDateTime($entry['end_at'])) ?></td>
                <td><?= $esc($entry['net_minutes']) ?></td>
                <td>
                    <span class="c-badge c-badge--<?= $esc($entry['status']) ?>">
                        <?= $esc($statusLabels[$entry['status']] ?? $entry['status']) ?>
                    </span>
                </td>
                <td>
                    <a href="/time-entries/<?= $esc($entry['id']) ?>" class="c-btn c-btn--sm c-btn--secondary">
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
