<?php

declare(strict_types=1);

/** @var array<string, mixed> $profile */
/** @var array<string, list<string>> $errors */
/** @var array<string, mixed> $old */

$title = $title ?? 'Mein Profil - Trackly';

$esc = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

$fieldClass = static fn(string $field): string =>
    'c-input' . (!empty($errors[$field]) ? ' is-invalid' : '');

$val = static function (string $field) use ($old, $profile): string {
    if (array_key_exists($field, $old)) {
        return (string) ($old[$field] ?? '');
    }

    return (string) ($profile[$field] ?? '');
};
?>
<div class="l-section">
    <div class="l-wrapper">
        <h1 class="u-mb-4">Mein Profil</h1>

        <p class="u-mb-5"><?= $esc($profile['email'] ?? '') ?></p>

        <form method="post" action="/profile" novalidate>
            <?= \App\Security\Csrf::inputHtml() ?>

            <div class="c-form-group u-mb-4">
                <label class="c-label" for="first_name">Vorname</label>
                <input class="<?= $fieldClass('first_name') ?>" type="text" id="first_name" name="first_name" value="<?= $esc($val('first_name')) ?>">
                <?php if (!empty($errors['first_name'])): ?>
                    <ul class="c-field-errors"><?php foreach ($errors['first_name'] as $m): ?><li><?= $esc($m) ?></li><?php endforeach; ?></ul>
                <?php endif; ?>
            </div>

            <div class="c-form-group u-mb-4">
                <label class="c-label" for="last_name">Nachname</label>
                <input class="<?= $fieldClass('last_name') ?>" type="text" id="last_name" name="last_name" value="<?= $esc($val('last_name')) ?>">
                <?php if (!empty($errors['last_name'])): ?>
                    <ul class="c-field-errors"><?php foreach ($errors['last_name'] as $m): ?><li><?= $esc($m) ?></li><?php endforeach; ?></ul>
                <?php endif; ?>
            </div>

            <div class="c-form-group u-mb-4">
                <label class="c-label" for="address_text">Adresse</label>
                <textarea class="<?= $fieldClass('address_text') ?>" id="address_text" name="address_text" rows="3"><?= $esc($val('address_text')) ?></textarea>
                <?php if (!empty($errors['address_text'])): ?>
                    <ul class="c-field-errors"><?php foreach ($errors['address_text'] as $m): ?><li><?= $esc($m) ?></li><?php endforeach; ?></ul>
                <?php endif; ?>
            </div>

            <div class="c-form-group u-mb-4">
                <label class="c-label" for="study_subjects_text">Studienfaecher</label>
                <textarea class="<?= $fieldClass('study_subjects_text') ?>" id="study_subjects_text" name="study_subjects_text" rows="2"><?= $esc($val('study_subjects_text')) ?></textarea>
                <?php if (!empty($errors['study_subjects_text'])): ?>
                    <ul class="c-field-errors"><?php foreach ($errors['study_subjects_text'] as $m): ?><li><?= $esc($m) ?></li><?php endforeach; ?></ul>
                <?php endif; ?>
            </div>

            <div class="c-form-group u-mb-4">
                <label class="c-label" for="study_program_text">Studiengang</label>
                <textarea class="<?= $fieldClass('study_program_text') ?>" id="study_program_text" name="study_program_text" rows="2"><?= $esc($val('study_program_text')) ?></textarea>
                <?php if (!empty($errors['study_program_text'])): ?>
                    <ul class="c-field-errors"><?php foreach ($errors['study_program_text'] as $m): ?><li><?= $esc($m) ?></li><?php endforeach; ?></ul>
                <?php endif; ?>
            </div>

            <div class="c-form-group u-mb-4">
                <label class="c-label" for="expected_graduation_date">Voraussichtlicher Abschluss</label>
                <input class="<?= $fieldClass('expected_graduation_date') ?>" type="date" id="expected_graduation_date" name="expected_graduation_date" value="<?= $esc($val('expected_graduation_date')) ?>">
                <?php if (!empty($errors['expected_graduation_date'])): ?>
                    <ul class="c-field-errors"><?php foreach ($errors['expected_graduation_date'] as $m): ?><li><?= $esc($m) ?></li><?php endforeach; ?></ul>
                <?php endif; ?>
            </div>

            <button class="c-btn c-btn--primary" type="submit">Profil speichern</button>
        </form>
    </div>
</div>
