<?php

declare(strict_types=1);

/** @var array<string, mixed> $profile */
/** @var string $activeTab */
/** @var array<string, list<string>> $profileErrors */
/** @var array<string, mixed> $profileOld */
/** @var array<string, list<string>> $accountErrors */
/** @var array<string, mixed> $accountOld */
/** @var array<string, list<string>> $passwordErrors */
/** @var array<string, mixed> $passwordOld */
/** @var list<array<string, mixed>> $auditRows */
/** @var int $auditPage */
/** @var bool $auditHasMore */

$title = $title ?? 'Mitarbeitenden-Konto - Trackly';

$esc = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

$profileFieldClass = static fn(string $field): string =>
    'c-input' . (!empty($profileErrors[$field]) ? ' is-invalid' : '');

$passwordFieldClass = static fn(string $field): string =>
    'c-input' . (!empty($passwordErrors[$field]) ? ' is-invalid' : '');

$accountFieldClass = static fn(string $field): string =>
    'c-input' . (!empty($accountErrors[$field]) ? ' is-invalid' : '');

$profileVal = static function (string $field) use ($profileOld, $profile): string {
    if (array_key_exists($field, $profileOld)) {
        return (string) ($profileOld[$field] ?? '');
    }

    return (string) ($profile[$field] ?? '');
};

$activeTab = $activeTab ?? 'profile';
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

        $old = is_array($change) && array_key_exists('old', $change) ? (string) ($change['old'] ?? 'null') : 'null';
        $new = is_array($change) && array_key_exists('new', $change) ? (string) ($change['new'] ?? 'null') : 'null';
        $parts[] = $field . ': ' . $old . ' -> ' . $new;
    }

    return implode('; ', $parts);
};
?>
<div class="l-section">
    <div class="l-wrapper">
        <div class="u-flex u-flex-between u-mb-4">
            <h1>Mitarbeitenden-Konto</h1>
            <a class="c-btn c-btn--secondary c-btn--sm" href="/coordination/employees">Zurueck</a>
        </div>

        <dl class="c-definition-list u-mb-6">
            <dt>E-Mail</dt>
            <dd><?= $esc($profile['email'] ?? '') ?></dd>
            <dt>Konto-Status</dt>
            <dd><?= ((int) ($profile['is_active'] ?? 0) === 1) ? 'Aktiv' : 'Inaktiv' ?></dd>
            <dt>Login</dt>
            <dd><?= ((int) ($profile['has_employee_account'] ?? 0) === 1) ? 'Vorhanden' : 'Kein Login-Konto' ?></dd>
        </dl>

        <div class="u-mb-5">
            <a class="c-btn c-btn--secondary c-btn--sm" href="/coordination/employees/<?= $esc($profile['id']) ?>?tab=profile">Profil</a>
            <a class="c-btn c-btn--secondary c-btn--sm" href="/coordination/employees/<?= $esc($profile['id']) ?>?tab=audit">Audit</a>
        </div>

        <?php if ($activeTab === 'audit'): ?>
            <h2 class="u-mb-3">Audit</h2>

            <?php if (empty($auditRows)): ?>
                <p>Keine Audit-Eintraege vorhanden.</p>
            <?php else: ?>
                <div class="c-table-wrapper u-mb-4">
                    <table class="c-table c-table--compact">
                        <thead>
                        <tr>
                            <th scope="col">Zeitpunkt</th>
                            <th scope="col">Actor</th>
                            <th scope="col">Action</th>
                            <th scope="col">Reason</th>
                            <th scope="col">Aenderungen</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($auditRows as $row): ?>
                            <tr>
                                <td><?= $esc((string) ($row['created_at'] ?? '')) ?></td>
                                <td><?= $esc((string) ($row['actor_display_name'] ?: $row['actor_email'] ?: ('#' . ($row['actor_user_id'] ?? '')))) ?></td>
                                <td><?= $esc((string) ($row['action'] ?? '')) ?></td>
                                <td><?= $esc((string) ($row['reason'] ?? '')) ?></td>
                                <td><?= $esc($formatDiff((array) ($row['diff'] ?? []))) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($auditHasMore): ?>
                    <a class="c-btn c-btn--secondary c-btn--sm" href="/coordination/employees/<?= $esc($profile['id']) ?>?tab=audit&audit_page=<?= $esc((string) ($auditPage + 1)) ?>">Mehr laden</a>
                <?php endif; ?>
            <?php endif; ?>
        <?php else: ?>

        <h2 class="u-mb-3">Profil bearbeiten</h2>
        <form method="post" action="/coordination/employees/<?= $esc($profile['id']) ?>/profile" novalidate class="u-mb-6">
            <?= \App\Security\Csrf::inputHtml() ?>

            <div class="c-form-group u-mb-4">
                <label class="c-label" for="first_name">Vorname</label>
                <input class="<?= $profileFieldClass('first_name') ?>" type="text" id="first_name" name="first_name" value="<?= $esc($profileVal('first_name')) ?>">
                <?php if (!empty($profileErrors['first_name'])): ?>
                    <ul class="c-field-errors"><?php foreach ($profileErrors['first_name'] as $m): ?><li><?= $esc($m) ?></li><?php endforeach; ?></ul>
                <?php endif; ?>
            </div>

            <div class="c-form-group u-mb-4">
                <label class="c-label" for="last_name">Nachname</label>
                <input class="<?= $profileFieldClass('last_name') ?>" type="text" id="last_name" name="last_name" value="<?= $esc($profileVal('last_name')) ?>">
                <?php if (!empty($profileErrors['last_name'])): ?>
                    <ul class="c-field-errors"><?php foreach ($profileErrors['last_name'] as $m): ?><li><?= $esc($m) ?></li><?php endforeach; ?></ul>
                <?php endif; ?>
            </div>

            <div class="c-form-group u-mb-4">
                <label class="c-label" for="address_text">Adresse</label>
                <textarea class="<?= $profileFieldClass('address_text') ?>" id="address_text" name="address_text" rows="3"><?= $esc($profileVal('address_text')) ?></textarea>
                <?php if (!empty($profileErrors['address_text'])): ?>
                    <ul class="c-field-errors"><?php foreach ($profileErrors['address_text'] as $m): ?><li><?= $esc($m) ?></li><?php endforeach; ?></ul>
                <?php endif; ?>
            </div>

            <div class="c-form-group u-mb-4">
                <label class="c-label" for="study_subjects_text">Studienfaecher</label>
                <textarea class="<?= $profileFieldClass('study_subjects_text') ?>" id="study_subjects_text" name="study_subjects_text" rows="2"><?= $esc($profileVal('study_subjects_text')) ?></textarea>
                <?php if (!empty($profileErrors['study_subjects_text'])): ?>
                    <ul class="c-field-errors"><?php foreach ($profileErrors['study_subjects_text'] as $m): ?><li><?= $esc($m) ?></li><?php endforeach; ?></ul>
                <?php endif; ?>
            </div>

            <div class="c-form-group u-mb-4">
                <label class="c-label" for="study_program_text">Studiengang</label>
                <textarea class="<?= $profileFieldClass('study_program_text') ?>" id="study_program_text" name="study_program_text" rows="2"><?= $esc($profileVal('study_program_text')) ?></textarea>
                <?php if (!empty($profileErrors['study_program_text'])): ?>
                    <ul class="c-field-errors"><?php foreach ($profileErrors['study_program_text'] as $m): ?><li><?= $esc($m) ?></li><?php endforeach; ?></ul>
                <?php endif; ?>
            </div>

            <div class="c-form-group u-mb-4">
                <label class="c-label" for="expected_graduation_date">Voraussichtlicher Abschluss</label>
                <input class="<?= $profileFieldClass('expected_graduation_date') ?>" type="date" id="expected_graduation_date" name="expected_graduation_date" value="<?= $esc($profileVal('expected_graduation_date')) ?>">
                <?php if (!empty($profileErrors['expected_graduation_date'])): ?>
                    <ul class="c-field-errors"><?php foreach ($profileErrors['expected_graduation_date'] as $m): ?><li><?= $esc($m) ?></li><?php endforeach; ?></ul>
                <?php endif; ?>
            </div>

            <div class="c-form-group u-mb-4">
                <label class="c-label" for="birth_date">Geburtsdatum</label>
                <input class="<?= $profileFieldClass('birth_date') ?>" type="date" id="birth_date" name="birth_date" value="<?= $esc($profileVal('birth_date')) ?>">
                <?php if (!empty($profileErrors['birth_date'])): ?>
                    <ul class="c-field-errors"><?php foreach ($profileErrors['birth_date'] as $m): ?><li><?= $esc($m) ?></li><?php endforeach; ?></ul>
                <?php endif; ?>
            </div>

            <div class="c-form-group u-mb-4">
                <label class="c-label" for="weekly_target_minutes">Wochenziel (Minuten)</label>
                <input class="<?= $profileFieldClass('weekly_target_minutes') ?>" type="number" min="0" max="10080" id="weekly_target_minutes" name="weekly_target_minutes" value="<?= $esc($profileVal('weekly_target_minutes')) ?>">
                <?php if (!empty($profileErrors['weekly_target_minutes'])): ?>
                    <ul class="c-field-errors"><?php foreach ($profileErrors['weekly_target_minutes'] as $m): ?><li><?= $esc($m) ?></li><?php endforeach; ?></ul>
                <?php endif; ?>
            </div>

            <div class="c-form-group u-mb-4">
                <label class="c-label" for="profile_reason">Begruendung (Pflicht bei sensiblen Feldern)</label>
                <textarea class="<?= $profileFieldClass('reason') ?>" id="profile_reason" name="reason" rows="2"><?= $esc((string) ($profileOld['reason'] ?? '')) ?></textarea>
                <?php if (!empty($profileErrors['reason'])): ?>
                    <ul class="c-field-errors"><?php foreach ($profileErrors['reason'] as $m): ?><li><?= $esc($m) ?></li><?php endforeach; ?></ul>
                <?php endif; ?>
            </div>

            <div class="c-form-group u-mb-4">
                <label class="c-label" for="contract_type_key">Vertragstyp</label>
                <input class="<?= $profileFieldClass('contract_type_key') ?>" type="text" id="contract_type_key" name="contract_type_key" value="<?= $esc($profileVal('contract_type_key')) ?>">
                <?php if (!empty($profileErrors['contract_type_key'])): ?>
                    <ul class="c-field-errors"><?php foreach ($profileErrors['contract_type_key'] as $m): ?><li><?= $esc($m) ?></li><?php endforeach; ?></ul>
                <?php endif; ?>
            </div>

            <button class="c-btn c-btn--primary" type="submit">Profil speichern</button>
        </form>

        <?php if ((int) ($profile['has_employee_account'] ?? 0) === 1): ?>
            <h2 class="u-mb-3">Konto verwalten</h2>
            <form method="post" action="/coordination/employees/<?= $esc($profile['id']) ?>/account" class="u-mb-6">
                <?= \App\Security\Csrf::inputHtml() ?>
                <label class="c-label" for="is_active">Konto aktiv</label>
                <div class="u-mb-4">
                    <input type="hidden" name="is_active" value="0">
                    <input type="checkbox" id="is_active" name="is_active" value="1" <?= ((int) ($profile['is_active'] ?? 0) === 1) ? 'checked' : '' ?>>
                </div>

                <div class="c-form-group u-mb-4">
                    <label class="c-label" for="account_reason">Begruendung (Pflicht bei Deaktivierung)</label>
                    <textarea class="<?= $accountFieldClass('reason') ?>" id="account_reason" name="reason" rows="2"><?= $esc((string) ($accountOld['reason'] ?? '')) ?></textarea>
                    <?php if (!empty($accountErrors['reason'])): ?>
                        <ul class="c-field-errors"><?php foreach ($accountErrors['reason'] as $m): ?><li><?= $esc($m) ?></li><?php endforeach; ?></ul>
                    <?php endif; ?>
                </div>

                <button class="c-btn c-btn--primary" type="submit">Konto-Status speichern</button>
            </form>

            <h2 class="u-mb-3">Initial-Passwort setzen</h2>
            <form method="post" action="/coordination/employees/<?= $esc($profile['id']) ?>/initial-password" novalidate>
                <?= \App\Security\Csrf::inputHtml() ?>

                <div class="c-form-group u-mb-4">
                    <label class="c-label" for="new_password">Neues Initial-Passwort</label>
                    <input class="<?= $passwordFieldClass('new_password') ?>" type="password" id="new_password" name="new_password" value="<?= $esc((string) ($passwordOld['new_password'] ?? '')) ?>" required>
                    <?php if (!empty($passwordErrors['new_password'])): ?>
                        <ul class="c-field-errors"><?php foreach ($passwordErrors['new_password'] as $m): ?><li><?= $esc($m) ?></li><?php endforeach; ?></ul>
                    <?php endif; ?>
                </div>

                <div class="c-form-group u-mb-4">
                    <label class="c-label" for="password_reason">Begruendung (optional)</label>
                    <textarea class="c-input" id="password_reason" name="reason" rows="2"><?= $esc((string) ($passwordOld['reason'] ?? '')) ?></textarea>
                </div>

                <div class="c-form-group u-mb-4">
                    <label class="c-label" for="new_password_confirm">Passwort wiederholen</label>
                    <input class="<?= $passwordFieldClass('new_password_confirm') ?>" type="password" id="new_password_confirm" name="new_password_confirm" value="<?= $esc((string) ($passwordOld['new_password_confirm'] ?? '')) ?>" required>
                    <?php if (!empty($passwordErrors['new_password_confirm'])): ?>
                        <ul class="c-field-errors"><?php foreach ($passwordErrors['new_password_confirm'] as $m): ?><li><?= $esc($m) ?></li><?php endforeach; ?></ul>
                    <?php endif; ?>
                </div>

                <button class="c-btn c-btn--primary" type="submit">Initial-Passwort setzen</button>
            </form>
        <?php else: ?>
            <div class="c-alert c-alert--info u-mb-6" role="note">
                Für diesen Mitarbeitenden gibt es aktuell kein Login-Konto.
            </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</div>
