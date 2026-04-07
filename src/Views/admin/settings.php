<?php
declare(strict_types=1);

use App\Domain\Settings\SettingDefinition;
use App\Domain\Settings\SettingsRegistry;

/** @var array<string, mixed>               $values   Current setting values (from DB / defaults) */
/** @var array<string, list<string>>        $errors   Validation errors (per-field + '_global') */
/** @var SettingsRegistry                   $registry Registry with all definitions */

$title = 'Einstellungen – Trackly';

/**
 * Render a single HTML-escaped value for an input.
 */
$esc = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
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
                $fieldName  = 'settings[' . $def->key . ']';
                $fieldId    = 'field_' . str_replace('.', '_', $def->key);
                $currentVal = $values[$def->key] ?? $def->default;
                $hasError   = !empty($errors[$def->key]);
                $inputClass = 'c-input' . ($hasError ? ' is-invalid' : '');
                ?>
                <div class="c-form-group u-mb-3">
                    <label class="c-label" for="<?= $esc($fieldId) ?>">
                        <?= $esc($def->label ?? $def->key) ?>
                        <?php if ($def->type === 'int' && $def->min !== null && $def->max !== null): ?>
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

                    <?php elseif ($def->uiType === 'time'): ?>
                        <input
                            class="<?= $inputClass ?>"
                            type="time"
                            id="<?= $esc($fieldId) ?>"
                            name="<?= $esc($fieldName) ?>"
                            value="<?= $esc($currentVal) ?>"
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
    </div>
</div>

