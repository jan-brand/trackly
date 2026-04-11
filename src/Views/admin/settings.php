<?php
declare(strict_types=1);

use App\Domain\Settings\SettingDefinition;
use App\Domain\Settings\SettingsRegistry;

/** @var array<string, mixed>                                        $values      Current setting values (from DB / defaults) */
/** @var array<string, list<string>>                                 $errors      Validation errors (per-field + '_global') */
/** @var SettingsRegistry                                            $registry    Registry with all definitions */
/** @var array<string, array{label: string, ui_type: string}>        $meta        DB-sourced label and ui_type per key */
/** @var array<string, list<int>>                                    $syncedYears Already-synced (state → years) map */

$title = 'Einstellungen – Trackly';

/**
 * Render a single HTML-escaped value for an input.
 */
$esc = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

/**
 * Convert an integer number of minutes to a HH:MM string for display.
 */
$minsToHHMM = static function (mixed $mins): string {
    $total = max(0, (int) $mins);
    return sprintf('%02d:%02d', intdiv($total, 60), $total % 60);
};

$syncedYears = $syncedYears ?? [];

$currentYear = (int) date('Y');
$yearRange   = range($currentYear - 1, $currentYear + 4);

$bundeslaender = [
    'BB' => 'Brandenburg',
    'BE' => 'Berlin',
    'BW' => 'Baden-Württemberg',
    'BY' => 'Bayern',
    'HB' => 'Bremen',
    'HE' => 'Hessen',
    'HH' => 'Hamburg',
    'MV' => 'Mecklenburg-Vorpommern',
    'NI' => 'Niedersachsen',
    'NW' => 'Nordrhein-Westfalen',
    'RP' => 'Rheinland-Pfalz',
    'SH' => 'Schleswig-Holstein',
    'SL' => 'Saarland',
    'SN' => 'Sachsen',
    'ST' => 'Sachsen-Anhalt',
    'TH' => 'Thüringen',
];

$defaultHolidayState = strtoupper((string) ($values['holiday.default_state'] ?? 'BE'));
if (!isset($bundeslaender[$defaultHolidayState])) {
    $defaultHolidayState = 'BE';
}
?>
<div class="l-section">
    <div class="l-wrapper">
        <h1>Einstellungen</h1>

        <?php if (!empty($errors['_global'])): ?>
        <div class="c-alert c-alert--error u-mb-4" role="alert">
            <ul>
                <?php foreach ($errors['_global'] as $msg): ?>
                    <li><?= $esc($msg) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <form method="post" action="/admin/settings">
            <?= \App\Security\Csrf::inputHtml() ?>

            <div class="c-form-group u-mb-4">
                <label class="c-label" for="reason">Begründung der Änderung</label>
                <textarea
                    class="c-input<?= !empty($errors['reason']) ? ' is-invalid' : '' ?>"
                    id="reason"
                    name="reason"
                    rows="3"
                    required
                    minlength="3"
                    placeholder="Mindestens 3 Zeichen"
                ></textarea>
                <?php if (!empty($errors['reason'])): ?>
                    <ul class="c-field-errors">
                        <?php foreach ($errors['reason'] as $msg): ?>
                            <li><?= $esc($msg) ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>

            <?php foreach ($registry->all() as $def): ?>
                <?php
                $fieldName      = 'settings[' . $def->key . ']';
                $fieldId        = 'field_' . str_replace('.', '_', $def->key);
                $currentVal     = $values[$def->key] ?? $def->default;
                $hasError       = !empty($errors[$def->key]);
                $inputClass     = 'c-input' . ($hasError ? ' is-invalid' : '');
                $effectiveLabel = $meta[$def->key]['label'] ?? $def->label ?? $def->key;
                $effectiveUiType = $meta[$def->key]['ui_type'] ?? $def->uiType ?? '';
                ?>
                <div class="c-form-group u-mb-3">
                    <label class="c-label" for="<?= $esc($fieldId) ?>">
                        <?= $esc($effectiveLabel) ?>
                        <?php if ($def->type === 'int' && $effectiveUiType !== 'duration' && $def->min !== null && $def->max !== null): ?>
                            <span class="c-label__hint">(<?= $esc($def->min) ?>–<?= $esc($def->max) ?>)</span>
                        <?php endif; ?>
                    </label>

                    <?php if ($def->type === 'bool'): ?>
                        <select class="<?= $inputClass ?>" id="<?= $esc($fieldId) ?>" name="<?= $esc($fieldName) ?>">
                            <option value="1"<?= $currentVal ? ' selected' : '' ?>>Ja</option>
                            <option value="0"<?= !$currentVal ? ' selected' : '' ?>>Nein</option>
                        </select>

                    <?php elseif ($def->type === 'enum' && $def->enumOptions !== null): ?>
                        <select class="<?= $inputClass ?>" id="<?= $esc($fieldId) ?>" name="<?= $esc($fieldName) ?>">
                            <?php foreach ($def->enumOptions as $i => $option): ?>
                                <option value="<?= $esc($option) ?>"<?= $currentVal === $option ? ' selected' : '' ?>>
                                    <?= $esc($def->enumLabels[$i] ?? $option) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                    <?php elseif ($effectiveUiType === 'duration'): ?>
                        <input
                            class="<?= $inputClass ?>"
                            type="text"
                            inputmode="numeric"
                            pattern="[0-9]+:[0-5][0-9]"
                            id="<?= $esc($fieldId) ?>"
                            name="<?= $esc($fieldName) ?>"
                            value="<?= $esc($minsToHHMM($currentVal)) ?>"
                            placeholder="z.B. 08:00"
                        >

                    <?php elseif ($effectiveUiType === 'time'): ?>
                        <input
                            class="<?= $inputClass ?>"
                            type="time"
                            id="<?= $esc($fieldId) ?>"
                            name="<?= $esc($fieldName) ?>"
                            value="<?= $esc($currentVal) ?>"
                        >

                    <?php elseif ($def->type === 'int'): ?>
                        <input
                            class="<?= $inputClass ?>"
                            type="number"
                            id="<?= $esc($fieldId) ?>"
                            name="<?= $esc($fieldName) ?>"
                            value="<?= $esc($currentVal) ?>"
                            <?= $def->min !== null ? ' min="' . $esc($def->min) . '"' : '' ?>
                            <?= $def->max !== null ? ' max="' . $esc($def->max) . '"' : '' ?>
                        >

                    <?php else: ?>
                        <input
                            class="<?= $inputClass ?>"
                            type="text"
                            id="<?= $esc($fieldId) ?>"
                            name="<?= $esc($fieldName) ?>"
                            value="<?= $esc($currentVal) ?>"
                        >
                    <?php endif; ?>

                    <?php if ($hasError): ?>
                        <ul class="c-field-errors">
                            <?php foreach ($errors[$def->key] as $msg): ?>
                                <li><?= $esc($msg) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>

            <div class="u-mt-4">
                <button class="c-btn c-btn--primary" type="submit">Speichern</button>
            </div>
        </form>

        <!-- ── Feiertage-Sync ──────────────────────────────────────────── -->
        <div class="u-mt-6">
            <h2>Feiertage synchronisieren</h2>
            <p>Importiert Feiertage für ein Bundesland und Jahr aus der konfigurierten API.</p>
            <button type="button" class="c-btn c-btn--secondary"
                    onclick="document.getElementById('holiday-sync-modal').showModal()">
                Feiertage importieren
            </button>
        </div>
    </div>
</div>

<!-- ── Holiday-Sync-Modal ─────────────────────────────────────────────── -->
<dialog id="holiday-sync-modal" class="c-modal" aria-labelledby="holiday-sync-modal-title">
    <form method="post" action="/admin/holidays/sync">
        <?= \App\Security\Csrf::inputHtml() ?>
        <div class="c-modal__header">
            <h2 class="c-modal__title" id="holiday-sync-modal-title">Feiertage importieren</h2>
            <button type="button" class="c-modal__close" aria-label="Dialog schließen"
                    onclick="document.getElementById('holiday-sync-modal').close()">&#x2715;</button>
        </div>
        <div class="c-modal__body">
            <div class="c-form-group u-mb-3">
                <label class="c-label" for="holiday-state">Bundesland</label>
                <select class="c-input" id="holiday-state" name="state" required
                        onchange="holidaySyncUpdateYears()">
                    <?php foreach ($bundeslaender as $code => $name): ?>
                        <option value="<?= $esc($code) ?>"<?= $code === $defaultHolidayState ? ' selected' : '' ?>><?= $esc($name) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="c-form-group">
                <label class="c-label" for="holiday-year">Jahr</label>
                <select class="c-input" id="holiday-year" name="year" required>
                    <?php foreach ($yearRange as $yr): ?>
                        <option value="<?= $esc($yr) ?>"><?= $esc($yr) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="c-modal__footer">
            <button type="button" class="c-btn c-btn--secondary"
                    onclick="document.getElementById('holiday-sync-modal').close()">Abbrechen</button>
            <button type="submit" class="c-btn c-btn--primary" id="holiday-sync-submit">Importieren</button>
        </div>
    </form>
</dialog>

<script>
(function () {
    var syncedYears = <?= json_encode($syncedYears, JSON_THROW_ON_ERROR) ?>;
    var yearRange   = <?= json_encode(array_values($yearRange), JSON_THROW_ON_ERROR) ?>;

    window.holidaySyncUpdateYears = function () {
        var state  = document.getElementById('holiday-state').value;
        var select = document.getElementById('holiday-year');
        var submit = document.getElementById('holiday-sync-submit');
        var synced = syncedYears[state] || [];
        var current = parseInt(select.value, 10);
        var firstEnabledYear = null;

        select.innerHTML = '';
        yearRange.forEach(function (yr) {
            var option = document.createElement('option');
            var alreadySynced = synced.indexOf(yr) !== -1;
            option.value       = yr;
            option.disabled    = alreadySynced;
            option.textContent = alreadySynced ? yr + ' (bereits importiert)' : String(yr);

            if (!alreadySynced && firstEnabledYear === null) {
                firstEnabledYear = yr;
            }

            select.appendChild(option);
        });

        // Restore previous selection if still allowed, otherwise use current year
        // when available, else the first not-yet-imported year.
        var nowYear = new Date().getFullYear();
        var currentIsEnabled = current && yearRange.indexOf(current) !== -1 && synced.indexOf(current) === -1;
        var nowIsEnabled = yearRange.indexOf(nowYear) !== -1 && synced.indexOf(nowYear) === -1;

        if (currentIsEnabled) {
            select.value = current;
        } else if (nowIsEnabled) {
            select.value = nowYear;
        } else if (firstEnabledYear !== null) {
            select.value = firstEnabledYear;
        }

        var hasEnabled = firstEnabledYear !== null;
        select.disabled = !hasEnabled;
        submit.disabled = !hasEnabled;
    };

    // Initialise on page load
    holidaySyncUpdateYears();
}());
</script>

