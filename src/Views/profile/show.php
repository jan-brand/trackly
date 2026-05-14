<?php

declare(strict_types=1);

/** @var array<string, mixed> $profile */
/** @var array<string, list<string>> $errors */
/** @var array<string, mixed> $old */
/** @var list<array<string, mixed>> $auditRows */
/** @var int $auditPage */
/** @var bool $auditHasMore */

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

$auditRows = $auditRows ?? [];
$auditPage = $auditPage ?? 1;
$auditHasMore = $auditHasMore ?? false;

$formatDiff = static function (array $diff): string {
    if (empty($diff)) {
        return 'Keine geaenderten Felder gespeichert.';
    }

    $parts = [];
    foreach ($diff as $field => $change) {
        if (is_array($change) && isset($change['changed']) && $change['changed'] === true) {
            $parts[] = $field . ': geaendert';
            continue;
        }

        $oldValue = is_array($change) && array_key_exists('old', $change) ? (string) ($change['old'] ?? 'null') : 'null';
        $newValue = is_array($change) && array_key_exists('new', $change) ? (string) ($change['new'] ?? 'null') : 'null';
        $parts[] = $field . ': ' . $oldValue . ' -> ' . $newValue;
    }

    return implode('; ', $parts);
};
?>
<div class="l-section">
    <div class="l-wrapper">
        <h1 class="u-mb-4">Mein Profil</h1>

        <p class="u-mb-5"><?= $esc($profile['email'] ?? '') ?></p>

        <div class="c-card u-mb-6">
        <form class="c-form" method="post" action="/profile" novalidate>
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

        <h2 class="u-mt-6 u-mb-3">Audit</h2>
        <?php if (empty($auditRows)): ?>
            <p>Keine Audit-Eintraege vorhanden.</p>
        <?php else: ?>
            <div class="c-table-wrapper u-mb-4">
                <table class="c-table c-table--compact">
                    <thead>
                    <tr>
                        <th scope="col">Zeitpunkt</th>
                        <th scope="col">Action</th>
                        <th scope="col">Reason</th>
                        <th scope="col">Aenderungen</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($auditRows as $row): ?>
                        <tr>
                            <td><?= $esc((string) ($row['created_at'] ?? '')) ?></td>
                            <td><?= $esc((string) ($row['action'] ?? '')) ?></td>
                            <td><?= $esc((string) ($row['reason'] ?? '')) ?></td>
                            <td><?= $esc($formatDiff((array) ($row['diff'] ?? []))) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($auditHasMore): ?>
                <a class="c-btn c-btn--secondary c-btn--sm" href="/profile?audit_page=<?= $esc((string) ($auditPage + 1)) ?>">Mehr laden</a>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>
