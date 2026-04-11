<?php

declare(strict_types=1);

/** @var array<string, list<string>> $errors */
/** @var array<string, mixed> $old */
/** @var array<string, string> $employmentTypes */

$title = $title ?? 'Neues Mitarbeitenden-Konto - Trackly';

$esc = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

$fieldClass = static fn(string $field): string =>
    'c-input' . (!empty($errors[$field]) ? ' is-invalid' : '');
?>
<div class="l-section">
    <div class="l-wrapper">
        <div class="u-flex u-flex-between u-mb-4">
            <h1>Neues Mitarbeitenden-Konto</h1>
            <a class="c-btn c-btn--secondary c-btn--sm" href="/coordination/employees">Zurueck</a>
        </div>

        <?php if (!empty($errors['_global'])): ?>
            <div class="c-alert c-alert--error u-mb-4" role="alert">
                <ul>
                    <?php foreach ($errors['_global'] as $msg): ?>
                        <li><?= $esc($msg) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form method="post" action="/coordination/employees/new" novalidate>
            <?= \App\Security\Csrf::inputHtml() ?>

            <div class="c-form-group u-mb-4">
                <label class="c-label" for="email">E-Mail</label>
                <input class="<?= $fieldClass('email') ?>" type="email" id="email" name="email" value="<?= $esc($old['email'] ?? '') ?>" required>
                <?php if (!empty($errors['email'])): ?>
                    <ul class="c-field-errors"><?php foreach ($errors['email'] as $msg): ?><li><?= $esc($msg) ?></li><?php endforeach; ?></ul>
                <?php endif; ?>
            </div>

            <div class="c-form-group u-mb-4">
                <label class="c-label" for="first_name">Vorname</label>
                <input class="<?= $fieldClass('first_name') ?>" type="text" id="first_name" name="first_name" value="<?= $esc($old['first_name'] ?? '') ?>" required>
                <?php if (!empty($errors['first_name'])): ?>
                    <ul class="c-field-errors"><?php foreach ($errors['first_name'] as $msg): ?><li><?= $esc($msg) ?></li><?php endforeach; ?></ul>
                <?php endif; ?>
            </div>

            <div class="c-form-group u-mb-4">
                <label class="c-label" for="last_name">Nachname</label>
                <input class="<?= $fieldClass('last_name') ?>" type="text" id="last_name" name="last_name" value="<?= $esc($old['last_name'] ?? '') ?>" required>
                <?php if (!empty($errors['last_name'])): ?>
                    <ul class="c-field-errors"><?php foreach ($errors['last_name'] as $msg): ?><li><?= $esc($msg) ?></li><?php endforeach; ?></ul>
                <?php endif; ?>
            </div>

            <div class="c-form-group u-mb-4">
                <label class="c-label" for="contract_type_key">Anstellungsart</label>
                <select class="<?= $fieldClass('contract_type_key') ?>" id="contract_type_key" name="contract_type_key" required>
                    <option value="">Bitte wählen</option>
                    <?php foreach ($employmentTypes as $key => $label): ?>
                        <option value="<?= $esc($key) ?>" <?= (($old['contract_type_key'] ?? '') === $key) ? 'selected' : '' ?>><?= $esc($label) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if (!empty($errors['contract_type_key'])): ?>
                    <ul class="c-field-errors"><?php foreach ($errors['contract_type_key'] as $msg): ?><li><?= $esc($msg) ?></li><?php endforeach; ?></ul>
                <?php endif; ?>
            </div>

            <div class="c-form-group u-mb-4">
                <label class="c-label" for="create_account">Nutzerkonto anlegen und Initial-PW per E-Mail senden</label>
                <div class="u-mt-2">
                    <input type="hidden" name="create_account" value="0">
                    <input type="checkbox" id="create_account" name="create_account" value="1" <?= !array_key_exists('create_account', $old) || !empty($old['create_account']) ? 'checked' : '' ?>>
                </div>
            </div>

            <button class="c-btn c-btn--primary" type="submit">Mitarbeitenden-Konto erstellen</button>
        </form>
    </div>
</div>
