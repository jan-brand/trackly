<?php
declare(strict_types=1);

/**
 * @var array<string, mixed>        $entry               time_entries row
 * @var string                      $displayName         user email / display name
 * @var list<array<string, mixed>>  $flags               message_admin flags
 * @var list<array<string, mixed>>  $auditLog            time_entry_audit_log rows (newest first)
 * @var list<array<string, mixed>>  $openClarifications  open clarifications
 */

$title = $title ?? 'Zeiteintrag – Koordination – Trackly';

$esc = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

$formatDateTime = static function (string $dt): string {
    try {
        return (new DateTimeImmutable($dt))->format('d.m.Y H:i');
    } catch (Throwable) {
        return $dt;
    }
};

$statusLabels = [
    'pending_approval' => 'Zur Prüfung',
    'in_clarification' => 'Rückfrage offen',
    'approved'         => 'Freigegeben',
    'cancelled'        => 'Storniert',
];
$statusLabel = $statusLabels[$entry['status']] ?? $entry['status'];
?>
<div class="l-section">
    <div class="l-wrapper">
        <div class="u-flex u-flex-between u-mb-4">
            <h1>Zeiteintrag – <?= $esc($displayName) ?></h1>
            <span class="c-badge c-badge--<?= $esc($entry['status']) ?>"><?= $esc($statusLabel) ?></span>
        </div>

        <!-- Entry summary -->
        <dl class="c-definition-list u-mb-6">
            <dt>Mitarbeiter</dt>
            <dd><?= $esc($displayName) ?></dd>
            <dt>Datum</dt>
            <dd><?= $esc($entry['date_local']) ?></dd>
            <dt>Beginn</dt>
            <dd><?= $esc($formatDateTime($entry['start_at'])) ?></dd>
            <dt>Ende</dt>
            <dd><?= $esc($formatDateTime($entry['end_at'])) ?></dd>
            <dt>Pause</dt>
            <dd><?= $esc($entry['break_minutes']) ?> min</dd>
            <dt>Netto</dt>
            <dd><?= $esc($entry['net_minutes']) ?> min</dd>
            <dt>Status</dt>
            <dd><?= $esc($statusLabel) ?></dd>
        </dl>

        <!-- Flags: message_admin -->
        <?php if (!empty($flags)): ?>
        <div class="c-alert c-alert--warning u-mb-6" role="status">
            <p class="u-fw-bold u-mb-2">Hinweise (message_admin)</p>
            <ul>
                <?php foreach ($flags as $flag): ?>
                <li><?= $esc($flag['flag_value'] ?? $flag['flag_key']) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <!-- Open clarifications -->
        <?php if (!empty($openClarifications)): ?>
        <div class="c-alert c-alert--info u-mb-6" role="status">
            <p class="u-fw-bold u-mb-2">Offene Rückfragen</p>
            <ul>
                <?php foreach ($openClarifications as $clar): ?>
                <li><?= $esc($clar['question_text']) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <!-- Approve form -->
        <?php if ($entry['status'] !== 'approved' && $entry['status'] !== 'cancelled'): ?>
        <div class="u-mb-6">
            <h2 class="u-mb-3">Freigabe</h2>
            <form method="post" action="/coordination/time-entries/<?= $esc($entry['id']) ?>/approve">
                <?= \App\Security\Csrf::inputHtml() ?>
                <button class="c-btn c-btn--primary" type="submit">Freigeben</button>
            </form>
        </div>
        <?php endif; ?>

        <!-- Request-clarification form -->
        <?php if ($entry['status'] !== 'cancelled'): ?>
        <div class="u-mb-6">
            <h2 class="u-mb-3">Rückfrage stellen</h2>
            <form method="post" action="/coordination/time-entries/<?= $esc($entry['id']) ?>/request-clarification">
                <?= \App\Security\Csrf::inputHtml() ?>
                <div class="c-form-group u-mb-4">
                    <label class="c-label" for="question_text">Frage</label>
                    <textarea
                        class="c-input"
                        id="question_text"
                        name="question_text"
                        rows="3"
                        required
                    ></textarea>
                </div>
                <button class="c-btn c-btn--secondary" type="submit">Rückfrage senden</button>
            </form>
        </div>
        <?php endif; ?>

        <!-- Audit log -->
        <div class="u-mb-6">
            <h2 class="u-mb-3">Audit-Log</h2>
            <?php if (empty($auditLog)): ?>
            <p class="u-text-muted">Keine Einträge vorhanden.</p>
            <?php else: ?>
            <div class="c-table-wrapper">
                <table class="c-table c-table--compact">
                    <thead>
                    <tr>
                        <th scope="col">Zeitpunkt</th>
                        <th scope="col">Aktion</th>
                        <th scope="col">Begründung</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($auditLog as $log): ?>
                    <tr>
                        <td><?= $esc($formatDateTime($log['created_at'])) ?></td>
                        <td><?= $esc($log['action']) ?></td>
                        <td><?= $esc($log['reason']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>

        <div class="u-mt-4">
            <a class="c-btn c-btn--secondary c-btn--sm" href="/coordination/queue">← Zurück zur Queue</a>
        </div>
    </div>
</div>
