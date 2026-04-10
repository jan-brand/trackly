<?php
declare(strict_types=1);

/** @var array<string, mixed>        $announcement  Row from announcements */
/** @var array<string, string>       $statusLabels  status → user-facing label */
/** @var array<string, list<string>> $errors        Validation errors */
/** @var array<string, mixed>        $old           Previous POST values */

$title = $title ?? 'Ankündigung – Trackly';

$esc = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

$fieldClass = static fn(string $field): string =>
    'c-input' . (!empty($errors[$field]) ? ' is-invalid' : '');

$fmtDt = static function (string $dt): string {
    try {
        return (new DateTimeImmutable($dt))->format('d.m.Y H:i');
    } catch (Throwable) {
        return $dt;
    }
};

$statusLabel = $statusLabels[$announcement['status']] ?? $announcement['status'];

// Pre-fill time fields from stored datetimes (HH:MM) when not in $old
$defaultStartTime = substr((string) $announcement['planned_start_at'], 11, 5);
$defaultEndTime   = substr((string) $announcement['planned_end_at'], 11, 5);
?>
<div class="l-section">
    <div class="l-wrapper">
        <div class="u-flex u-flex-between u-mb-4">
            <h1>Ankündigung vom <?= $esc($announcement['date_local']) ?></h1>
            <span class="c-badge c-badge--<?= $esc($announcement['status']) ?>"><?= $esc($statusLabel) ?></span>
        </div>

        <dl class="c-definition-list u-mb-6">
            <dt>Geplanter Beginn</dt>
            <dd><?= $esc($fmtDt((string) $announcement['planned_start_at'])) ?></dd>
            <dt>Geplantes Ende</dt>
            <dd><?= $esc($fmtDt((string) $announcement['planned_end_at'])) ?></dd>
            <dt>Pause</dt>
            <dd><?= $esc($announcement['break_minutes']) ?> min</dd>
            <dt>Netto</dt>
            <dd><?= $esc($announcement['net_minutes']) ?> min</dd>
            <dt>Begründung</dt>
            <dd><?= $esc($announcement['reason']) ?></dd>
        </dl>

        <?php if (!empty($errors['_global'])): ?>
        <div class="c-alert c-alert--error u-mb-4" role="alert">
            <ul>
                <?php foreach ($errors['_global'] as $msg): ?>
                    <li><?= $esc($msg) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <h2 class="u-mb-3">Ankündigung bearbeiten</h2>

        <form method="post" action="/announcements/<?= $esc($announcement['id']) ?>" novalidate>
            <?= \App\Security\Csrf::inputHtml() ?>

            <div class="c-form-group u-mb-4">
                <label class="c-label" for="date">Datum</label>
                <input
                    class="<?= $fieldClass('date') ?>"
                    type="date"
                    id="date"
                    name="date"
                    value="<?= $esc($old['date'] ?? $announcement['date_local']) ?>"
                    required
                >
                <?php if (!empty($errors['date'])): ?>
                    <ul class="c-field-errors"><?php foreach ($errors['date'] as $m): ?><li><?= $esc($m) ?></li><?php endforeach; ?></ul>
                <?php endif; ?>
            </div>

            <div class="c-form-group u-mb-4">
                <label class="c-label" for="planned_start_time">Geplanter Beginn</label>
                <input
                    class="<?= $fieldClass('planned_start_time') ?>"
                    type="time"
                    id="planned_start_time"
                    name="planned_start_time"
                    value="<?= $esc($old['planned_start_time'] ?? $defaultStartTime) ?>"
                    required
                >
                <?php if (!empty($errors['planned_start_time'])): ?>
                    <ul class="c-field-errors"><?php foreach ($errors['planned_start_time'] as $m): ?><li><?= $esc($m) ?></li><?php endforeach; ?></ul>
                <?php endif; ?>
            </div>

            <div class="c-form-group u-mb-4">
                <label class="c-label" for="planned_end_time">Geplantes Ende</label>
                <input
                    class="<?= $fieldClass('planned_end_time') ?>"
                    type="time"
                    id="planned_end_time"
                    name="planned_end_time"
                    value="<?= $esc($old['planned_end_time'] ?? $defaultEndTime) ?>"
                    required
                >
                <?php if (!empty($errors['planned_end_time'])): ?>
                    <ul class="c-field-errors"><?php foreach ($errors['planned_end_time'] as $m): ?><li><?= $esc($m) ?></li><?php endforeach; ?></ul>
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
                    value="<?= $esc($old['break_minutes'] ?? $announcement['break_minutes']) ?>"
                    required
                >
                <?php if (!empty($errors['break_minutes'])): ?>
                    <ul class="c-field-errors"><?php foreach ($errors['break_minutes'] as $m): ?><li><?= $esc($m) ?></li><?php endforeach; ?></ul>
                <?php endif; ?>
            </div>

            <div class="c-form-group u-mb-4">
                <label class="c-label" for="reason">Begründung</label>
                <textarea
                    class="<?= $fieldClass('reason') ?>"
                    id="reason"
                    name="reason"
                    rows="3"
                    required
                ><?= $esc($old['reason'] ?? $announcement['reason']) ?></textarea>
                <?php if (!empty($errors['reason'])): ?>
                    <ul class="c-field-errors"><?php foreach ($errors['reason'] as $m): ?><li><?= $esc($m) ?></li><?php endforeach; ?></ul>
                <?php endif; ?>
            </div>

            <button class="c-btn c-btn--primary" type="submit">Speichern</button>
        </form>

        <div class="u-mt-4">
            <a class="c-btn c-btn--secondary c-btn--sm" href="/announcements">← Zurück zur Übersicht</a>
        </div>
    </div>
</div>
