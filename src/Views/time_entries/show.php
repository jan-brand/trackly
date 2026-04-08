<?php
declare(strict_types=1);

/** @var array<string, mixed>                $entry        Row from time_entries */
/** @var list<array<string, mixed>>          $flags        Rows from time_entry_flags (ordered by sort_index) */
/** @var array<string, string>               $flagMessages flag_key → user-facing message */
/** @var array<string, string>               $statusLabels status   → user-facing label */
/** @var array<string, list<string>>         $errors       Validation errors */
/** @var array<string, mixed>                $old          Previous POST values */

$title = $title ?? 'Zeiteintrag – Trackly';

$esc = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

$fieldClass = static fn(string $field) use ($errors): string =>
    'c-input' . (!empty($errors[$field]) ? ' is-invalid' : '');

$formatDateTime = static function (string $dt): string {
    try {
        return (new DateTimeImmutable($dt))->format('d.m.Y H:i');
    } catch (Throwable) {
        return $dt;
    }
};

$statusLabel = $statusLabels[$entry['status']] ?? $entry['status'];
$isCancelled = $entry['status'] === 'cancelled';
?>
<div class="l-section">
    <div class="l-wrapper">
        <div class="u-flex u-flex-between u-mb-4">
            <h1>Zeiteintrag vom <?= $esc($entry['date_local']) ?></h1>
            <span class="c-badge c-badge--<?= $esc($entry['status']) ?>"><?= $esc($statusLabel) ?></span>
        </div>

        <!-- Entry summary -->
        <dl class="c-definition-list u-mb-6">
            <dt>Beginn</dt>
            <dd><?= $esc($formatDateTime($entry['start_at'])) ?></dd>
            <dt>Ende</dt>
            <dd><?= $esc($formatDateTime($entry['end_at'])) ?></dd>
            <dt>Pause</dt>
            <dd><?= $esc($entry['break_minutes']) ?> min</dd>
            <dt>Netto</dt>
            <dd><?= $esc($entry['net_minutes']) ?> min</dd>
        </dl>

        <!-- Flags -->
        <?php if (!empty($flags)): ?>
        <div class="c-alert c-alert--warning u-mb-6" role="status">
            <p class="u-fw-bold u-mb-2">Hinweise</p>
            <ul>
                <?php foreach ($flags as $flag): ?>
                <li><?= $esc($flagMessages[$flag['flag_key']] ?? $flag['flag_key']) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <?php if (!empty($errors['_global'])): ?>
        <div class="c-alert c-alert--error u-mb-4" role="alert">
            <ul>
                <?php foreach ($errors['_global'] as $msg): ?>
                    <li><?= $esc($msg) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <!-- Edit form (hidden when cancelled) -->
        <?php if (!$isCancelled): ?>
        <h2 class="u-mb-3">Eintrag bearbeiten</h2>

        <form method="post" action="/time-entries/<?= $esc($entry['id']) ?>" novalidate class="u-mb-6">
            <?= \App\Security\Csrf::inputHtml() ?>

            <div class="c-form-group u-mb-4">
                <label class="c-label" for="date">Datum</label>
                <input
                    class="<?= $fieldClass('date') ?>"
                    type="date"
                    id="date"
                    name="date"
                    value="<?= $esc($old['date'] ?? $entry['date_local']) ?>"
                    required
                >
                <?php if (!empty($errors['date'])): ?>
                    <ul class="c-field-errors"><?php foreach ($errors['date'] as $m): ?><li><?= $esc($m) ?></li><?php endforeach; ?></ul>
                <?php endif; ?>
            </div>

            <div class="c-form-group u-mb-4">
                <label class="c-label" for="start_time">Beginn</label>
                <input
                    class="<?= $fieldClass('start_time') ?>"
                    type="time"
                    id="start_time"
                    name="start_time"
                    value="<?= $esc($old['start_time'] ?? substr((string) $entry['start_at'], 11, 5)) ?>"
                    required
                >
                <?php if (!empty($errors['start_time'])): ?>
                    <ul class="c-field-errors"><?php foreach ($errors['start_time'] as $m): ?><li><?= $esc($m) ?></li><?php endforeach; ?></ul>
                <?php endif; ?>
            </div>

            <div class="c-form-group u-mb-4">
                <label class="c-label" for="end_time">Ende</label>
                <input
                    class="<?= $fieldClass('end_time') ?>"
                    type="time"
                    id="end_time"
                    name="end_time"
                    value="<?= $esc($old['end_time'] ?? substr((string) $entry['end_at'], 11, 5)) ?>"
                    required
                >
                <?php if (!empty($errors['end_time'])): ?>
                    <ul class="c-field-errors"><?php foreach ($errors['end_time'] as $m): ?><li><?= $esc($m) ?></li><?php endforeach; ?></ul>
                <?php endif; ?>
            </div>

            <div class="c-form-group u-mb-4">
                <label class="c-label" for="break_minutes">Pause (Minuten)</label>
                <input
                    class="<?= $fieldClass('break_minutes') ?>"
                    type="number"
                    id="break_minutes"
                    name="break_minutes"
                    min="0"
                    value="<?= $esc($old['break_minutes'] ?? $entry['break_minutes']) ?>"
                    required
                >
                <?php if (!empty($errors['break_minutes'])): ?>
                    <ul class="c-field-errors"><?php foreach ($errors['break_minutes'] as $m): ?><li><?= $esc($m) ?></li><?php endforeach; ?></ul>
                <?php endif; ?>
            </div>

            <div class="c-form-group u-mb-4">
                <label class="c-label" for="reason">Begründung der Änderung</label>
                <textarea
                    class="<?= $fieldClass('reason') ?>"
                    id="reason"
                    name="reason"
                    rows="3"
                    required
                ><?= $esc($old['reason'] ?? '') ?></textarea>
                <?php if (!empty($errors['reason'])): ?>
                    <ul class="c-field-errors"><?php foreach ($errors['reason'] as $m): ?><li><?= $esc($m) ?></li><?php endforeach; ?></ul>
                <?php endif; ?>
            </div>

            <button class="c-btn c-btn--primary" type="submit">Speichern</button>
        </form>

        <!-- Cancel form -->
        <h2 class="u-mb-3">Eintrag stornieren</h2>
        <form method="post" action="/time-entries/<?= $esc($entry['id']) ?>/cancel" novalidate>
            <?= \App\Security\Csrf::inputHtml() ?>
            <div class="c-form-group u-mb-4">
                <label class="c-label" for="cancel_reason">Stornierungsgrund</label>
                <textarea
                    class="c-input<?= !empty($errors['reason']) && isset($old['cancel_reason']) ? ' is-invalid' : '' ?>"
                    id="cancel_reason"
                    name="reason"
                    rows="2"
                    required
                ></textarea>
            </div>
            <button class="c-btn c-btn--danger" type="submit">Stornieren</button>
        </form>
        <?php endif; ?>

        <div class="u-mt-4">
            <a class="c-btn c-btn--secondary c-btn--sm" href="/time-entries">← Zurück zur Übersicht</a>
        </div>
    </div>
</div>
