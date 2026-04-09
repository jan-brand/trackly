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

$formatDuration = static function (int $minutes): string {
    $minutes = max(0, $minutes);
    $hours = intdiv($minutes, 60);
    $remainder = $minutes % 60;

    return sprintf('%d h %02d min', $hours, $remainder);
};

$entryCount = count($entries);
$totalMinutes = 0;
$approvedCount = 0;
$pendingCount = 0;
$clarificationCount = 0;
$cancelledCount = 0;

foreach ($entries as $entry) {
    $totalMinutes += (int) ($entry['net_minutes'] ?? 0);

    switch ($entry['status'] ?? '') {
        case 'approved':
            $approvedCount++;
            break;
        case 'pending_approval':
            $pendingCount++;
            break;
        case 'in_clarification':
            $clarificationCount++;
            break;
        case 'cancelled':
            $cancelledCount++;
            break;
    }
}
?>
<div class="l-section">
    <div class="l-wrapper">
        <div class="l-stack l-stack--lg">
            <section class="l-card">
                <div class="l-stack l-stack--sm">
                    <div class="l-cluster l-cluster--space">
                        <div class="l-stack l-stack--xs">
                            <p class="u-text-xs u-font-semibold u-text-primary u-mb-0">Zeiterfassung</p>
                            <h1 class="u-mb-0">Meine Zeiteinträge</h1>
                            <p class="u-text-muted u-mb-0">Behalte Arbeitszeiten, Status und Nettozeit im Blick.</p>
                        </div>

                        <div class="l-cluster l-cluster--end">
                            <a class="c-btn c-btn--primary" href="/time-entries/new">Neuer Eintrag</a>
                        </div>
                    </div>

                    <div class="l-grid">
                        <div class="u-border u-rounded-lg u-p-4">
                            <p class="u-text-xs u-font-semibold u-text-muted u-mb-2">Einträge gesamt</p>
                            <p class="u-text-2xl u-font-bold u-mb-0"><?= $esc($entryCount) ?></p>
                        </div>
                        <div class="u-border u-rounded-lg u-p-4">
                            <p class="u-text-xs u-font-semibold u-text-muted u-mb-2">Nettozeit</p>
                            <p class="u-text-2xl u-font-bold u-mb-0"><?= $esc($formatDuration($totalMinutes)) ?></p>
                        </div>
                        <div class="u-border u-rounded-lg u-p-4">
                            <p class="u-text-xs u-font-semibold u-text-muted u-mb-2">Freigegeben</p>
                            <p class="u-text-2xl u-font-bold u-mb-0"><?= $esc($approvedCount) ?></p>
                        </div>
                    </div>
                </div>
            </section>

            <?php if (empty($entries)): ?>
            <section class="l-card u-text-center">
                <div class="l-stack l-stack--sm">
                    <p class="u-text-xs u-font-semibold u-text-primary u-mb-0">Noch kein Verlauf</p>
                    <h2 class="u-mb-0">Du hast noch keine Zeiteinträge angelegt.</h2>
                    <p class="u-text-muted u-mb-0">Lege den ersten Eintrag an, um Zeiten, Netto-Minuten und Status direkt zu dokumentieren.</p>
                    <div class="l-cluster l-cluster--center u-mt-4">
                        <a class="c-btn c-btn--primary" href="/time-entries/new">Ersten Eintrag erstellen</a>
                    </div>
                </div>
            </section>
            <?php else: ?>
            <section class="l-card u-p-0 u-overflow-hidden">
                <div class="c-table-wrapper">
                    <table class="c-table c-table--compact">
                        <thead>
                        <tr>
                            <th scope="col">Datum</th>
                            <th scope="col">Beginn</th>
                            <th scope="col">Ende</th>
                            <th scope="col" class="c-table__num">Netto</th>
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
                            <td class="c-table__num"><?= $esc($entry['net_minutes']) ?> min</td>
                            <td>
                                <span class="c-badge c-badge--<?= $esc($entry['status']) ?>">
                                    <?= $esc($statusLabels[$entry['status']] ?? $entry['status']) ?>
                                </span>
                            </td>
                            <td class="u-text-right">
                                <a href="/time-entries/<?= $esc($entry['id']) ?>" class="c-btn c-btn--sm c-btn--secondary">
                                    Details
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
            <?php endif; ?>

            <section class="l-grid">
                <div class="u-border u-rounded-lg u-p-4">
                    <p class="u-text-xs u-font-semibold u-text-muted u-mb-2">Zur Prüfung</p>
                    <p class="u-text-2xl u-font-bold u-mb-0"><?= $esc($pendingCount) ?></p>
                </div>
                <div class="u-border u-rounded-lg u-p-4">
                    <p class="u-text-xs u-font-semibold u-text-muted u-mb-2">Rückfrage offen</p>
                    <p class="u-text-2xl u-font-bold u-mb-0"><?= $esc($clarificationCount) ?></p>
                </div>
                <div class="u-border u-rounded-lg u-p-4">
                    <p class="u-text-xs u-font-semibold u-text-muted u-mb-2">Storniert</p>
                    <p class="u-text-2xl u-font-bold u-mb-0"><?= $esc($cancelledCount) ?></p>
                </div>
            </section>
        </div>
    </div>
</div>
