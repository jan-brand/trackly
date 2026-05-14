<?php
declare(strict_types=1);

/** @var array<string, list<string>> $errors  Validation errors (per-field + '_global') */
/** @var array<string, mixed>        $old     Previous POST values to re-populate form */

$title = $title ?? 'Neuer Zeiteintrag – Trackly';

$esc = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

$fieldClass = static fn(string $field): string =>
    'c-input' . (!empty($errors[$field]) ? ' is-invalid' : '');
?>
<div class="l-section">
    <div class="l-wrapper">
        <h1>Neuer Zeiteintrag</h1>

        <?php if (!empty($errors['_global'])): ?>
        <div class="c-alert c-alert--error u-mb-4" role="alert">
            <ul>
                <?php foreach ($errors['_global'] as $msg): ?>
                    <li><?= $esc($msg) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <div class="c-card u-mb-6">
        <form class="c-form" method="post" action="/time-entries" novalidate>
            <?= \App\Security\Csrf::inputHtml() ?>

            <div class="c-form-group u-mb-4">
                <label class="c-label" for="date">Datum</label>
                <input
                    class="<?= $fieldClass('date') ?>"
                    type="date"
                    id="date"
                    name="date"
                    value="<?= $esc($old['date'] ?? '') ?>"
                    required
                >
                <?php if (!empty($errors['date'])): ?>
                    <ul class="c-field-errors">
                        <?php foreach ($errors['date'] as $msg): ?>
                            <li><?= $esc($msg) ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>

            <div class="c-form-group u-mb-4">
                <label class="c-label" for="start_time">Beginn</label>
                <input
                    class="<?= $fieldClass('start_time') ?>"
                    type="time"
                    id="start_time"
                    name="start_time"
                    value="<?= $esc($old['start_time'] ?? '') ?>"
                    required
                >
                <?php if (!empty($errors['start_time'])): ?>
                    <ul class="c-field-errors">
                        <?php foreach ($errors['start_time'] as $msg): ?>
                            <li><?= $esc($msg) ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>

            <div class="c-form-group u-mb-4">
                <label class="c-label" for="end_time">Ende</label>
                <input
                    class="<?= $fieldClass('end_time') ?>"
                    type="time"
                    id="end_time"
                    name="end_time"
                    value="<?= $esc($old['end_time'] ?? '') ?>"
                    required
                >
                <?php if (!empty($errors['end_time'])): ?>
                    <ul class="c-field-errors">
                        <?php foreach ($errors['end_time'] as $msg): ?>
                            <li><?= $esc($msg) ?></li>
                        <?php endforeach; ?>
                    </ul>
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
                    value="<?= $esc($old['break_minutes'] ?? '0') ?>"
                    required
                >
                <?php if (!empty($errors['break_minutes'])): ?>
                    <ul class="c-field-errors">
                        <?php foreach ($errors['break_minutes'] as $msg): ?>
                            <li><?= $esc($msg) ?></li>
                        <?php endforeach; ?>
                    </ul>
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
                ><?= $esc($old['reason'] ?? '') ?></textarea>
                <?php if (!empty($errors['reason'])): ?>
                    <ul class="c-field-errors">
                        <?php foreach ($errors['reason'] as $msg): ?>
                            <li><?= $esc($msg) ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>

            <div class="c-form-actions">
                <button class="c-btn c-btn--primary" type="submit">Eintrag erstellen</button>
                <a class="c-btn c-btn--secondary" href="/time-entries">Abbrechen</a>
            </div>
        </form>
        </div>
    </div>
</div>
