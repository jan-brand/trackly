<?php
declare(strict_types=1);

/**
 * @var string                      $heading   Page heading (e.g. "Queue – Zeiteinträge")
 * @var string                      $tab       Active tab: 'times' | 'announcements'
 * @var list<array<string, mixed>>  $entries   time_entries rows (with user_email)
 * @var string                      $month     Active month filter (YYYY-MM)
 * @var string                      $status    Active status filter
 * @var string                      $sort      Active sort
 * @var int|null                    $userId    Active user_id filter
 */

$title   = $title   ?? 'Koordinations-Queue – Trackly';
$heading = $heading ?? 'Queue – Zeiteinträge';

$esc = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

$statusLabels = [
    'pending_approval' => 'Zur Prüfung',
    'in_clarification' => 'Rückfrage offen',
    'approved'         => 'Freigegeben',
    'cancelled'        => 'Storniert',
];

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
        <h1 class="u-mb-4"><?= $esc($heading) ?></h1>

        <!-- Tab navigation -->
        <nav class="u-mb-4" aria-label="Queue-Tabs">
            <a class="c-btn c-btn--sm <?= $tab === 'times' ? 'c-btn--primary' : 'c-btn--secondary' ?>"
               href="/coordination/queue?tab=times&month=<?= $esc($month) ?>&status=<?= $esc($status) ?>&sort=<?= $esc($sort) ?>">
                Zeiteinträge
            </a>
            <a class="c-btn c-btn--sm <?= $tab === 'announcements' ? 'c-btn--primary' : 'c-btn--secondary' ?>"
               href="/coordination/queue?tab=announcements&month=<?= $esc($month) ?>&status=<?= $esc($status) ?>&sort=<?= $esc($sort) ?>">
                Ankündigungen
            </a>
        </nav>

        <?php if ($tab === 'announcements'):
            $announcementStatusLabels = [
                'pending_approval' => 'Zur Prüfung',
                'approved'         => 'Freigegeben',
                'rejected'         => 'Abgelehnt',
            ];
        ?>

        <!-- Filter bar (announcements) -->
        <form method="get" action="/coordination/queue" class="u-mb-4">
            <input type="hidden" name="tab" value="announcements">
            <div class="l-cluster">
                <div>
                    <label class="c-label" for="ann-month">Monat</label>
                    <input class="c-input" type="month" id="ann-month" name="month"
                           value="<?= $esc($month) ?>">
                </div>
                <div>
                    <label class="c-label" for="ann-status">Status</label>
                    <select class="c-input" id="ann-status" name="status">
                        <option value="all"              <?= $status === 'all'              ? 'selected' : '' ?>>Alle</option>
                        <option value="pending_approval" <?= $status === 'pending_approval' ? 'selected' : '' ?>>Zur Prüfung</option>
                        <option value="approved"         <?= $status === 'approved'         ? 'selected' : '' ?>>Freigegeben</option>
                        <option value="rejected"         <?= $status === 'rejected'         ? 'selected' : '' ?>>Abgelehnt</option>
                    </select>
                </div>
                <div>
                    <label class="c-label" for="ann-sort">Sortierung</label>
                    <select class="c-input" id="ann-sort" name="sort">
                        <option value="oldest"     <?= $sort === 'oldest'     ? 'selected' : '' ?>>Älteste zuerst</option>
                        <option value="newest"     <?= $sort === 'newest'     ? 'selected' : '' ?>>Neueste zuerst</option>
                        <option value="person_asc" <?= $sort === 'person_asc' ? 'selected' : '' ?>>Person A–Z</option>
                    </select>
                </div>
                <div class="u-self-end">
                    <button class="c-btn c-btn--secondary" type="submit">Filtern</button>
                </div>
            </div>
        </form>

        <?php if (empty($entries)): ?>
        <p class="u-text-muted">Keine Ankündigungen für die gewählten Filter.</p>
        <?php else: ?>
        <div class="c-table-wrapper">
            <table class="c-table c-table--compact">
                <thead>
                <tr>
                    <th scope="col">Mitarbeiter</th>
                    <th scope="col">Datum</th>
                    <th scope="col">Beginn</th>
                    <th scope="col">Ende</th>
                    <th scope="col" class="c-table__num">Netto</th>
                    <th scope="col">Grund</th>
                    <th scope="col">Status</th>
                    <th scope="col"></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($entries as $ann): ?>
                <tr>
                    <td><?= $esc($ann['user_email']) ?></td>
                    <td><?= $esc($ann['date_local']) ?></td>
                    <td><?= $esc($formatDateTime($ann['planned_start_at'])) ?></td>
                    <td><?= $esc($formatDateTime($ann['planned_end_at'])) ?></td>
                    <td class="c-table__num"><?= $esc($ann['net_minutes']) ?> min</td>
                    <td><?= $esc($ann['reason']) ?></td>
                    <td>
                        <span class="c-badge c-badge--<?= $esc($ann['status']) ?>">
                            <?= $esc($announcementStatusLabels[$ann['status']] ?? $ann['status']) ?>
                        </span>
                    </td>
                    <td class="u-text-right">
                        <?php if ($ann['status'] === 'pending_approval'): ?>
                        <form method="post" action="/coordination/announcements/<?= $esc($ann['id']) ?>/approve" class="u-inline">
                            <?= \App\Security\Csrf::inputHtml() ?>
                            <button class="c-btn c-btn--sm c-btn--primary" type="submit">Freigeben</button>
                        </form>
                        <form method="post" action="/coordination/announcements/<?= $esc($ann['id']) ?>/reject" class="u-inline u-ml-2">
                            <?= \App\Security\Csrf::inputHtml() ?>
                            <input class="c-input c-input--sm u-ml-2" type="text" name="rejection_reason"
                                   placeholder="Begründung (Pflicht)" required minlength="3"
                                   aria-label="Begründung der Ablehnung">
                            <button class="c-btn c-btn--sm c-btn--secondary" type="submit">Ablehnen</button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <?php else: ?>

        <!-- Filter bar -->
        <form method="get" action="/coordination/queue" class="u-mb-4">
            <input type="hidden" name="tab" value="times">
            <div class="l-cluster">
                <div>
                    <label class="c-label" for="month">Monat</label>
                    <input class="c-input" type="month" id="month" name="month"
                           value="<?= $esc($month) ?>">
                </div>
                <div>
                    <label class="c-label" for="status">Status</label>
                    <select class="c-input" id="status" name="status">
                        <option value="all"              <?= $status === 'all'              ? 'selected' : '' ?>>Alle</option>
                        <option value="pending_approval" <?= $status === 'pending_approval' ? 'selected' : '' ?>>Zur Prüfung</option>
                        <option value="in_clarification" <?= $status === 'in_clarification' ? 'selected' : '' ?>>Rückfrage offen</option>
                    </select>
                </div>
                <div>
                    <label class="c-label" for="sort">Sortierung</label>
                    <select class="c-input" id="sort" name="sort">
                        <option value="oldest"     <?= $sort === 'oldest'     ? 'selected' : '' ?>>Älteste zuerst</option>
                        <option value="newest"     <?= $sort === 'newest'     ? 'selected' : '' ?>>Neueste zuerst</option>
                        <option value="person_asc" <?= $sort === 'person_asc' ? 'selected' : '' ?>>Person A–Z</option>
                    </select>
                </div>
                <div class="u-self-end">
                    <button class="c-btn c-btn--secondary" type="submit">Filtern</button>
                </div>
            </div>
        </form>

        <?php if (empty($entries)): ?>
        <p class="u-text-muted">Keine Zeiteinträge für die gewählten Filter.</p>
        <?php else: ?>
        <div class="c-table-wrapper">
            <table class="c-table c-table--compact">
                <thead>
                <tr>
                    <th scope="col">Mitarbeiter</th>
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
                    <td><?= $esc($entry['user_email']) ?></td>
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
                        <a href="/coordination/time-entries/<?= $esc($entry['id']) ?>"
                           class="c-btn c-btn--sm c-btn--secondary">Details</a>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <?php endif; ?>
    </div>
</div>
