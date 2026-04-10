<?php
declare(strict_types=1);

/**
 * @var string                         $month      YYYY-MM
 * @var bool                           $isElevated True for coordination/treasurer/admin
 * @var list<array<string, mixed>>     $users      All active users (for single_user select)
 */

$title = $title ?? 'Export – Trackly';

$esc = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
?>
<div class="l-section">
    <div class="l-wrapper l-wrapper--narrow">
        <h1 class="u-mb-4">Export</h1>

        <form method="post" class="l-stack">
            <?= \App\Security\Csrf::inputHtml() ?>

            <div class="c-field">
                <label class="c-field__label" for="month">Monat</label>
                <input
                    class="c-field__input"
                    type="month"
                    id="month"
                    name="month"
                    value="<?= $esc($month) ?>"
                    required
                >
            </div>

            <?php if ($isElevated): ?>
            <fieldset class="c-field">
                <legend class="c-field__label">Umfang</legend>
                <div class="l-stack l-stack--sm">
                    <label class="c-radio">
                        <input type="radio" name="scope" value="self" checked>
                        Eigene Einträge
                    </label>
                    <label class="c-radio">
                        <input type="radio" name="scope" value="single_user">
                        Einzelner Mitarbeiter
                    </label>
                    <label class="c-radio">
                        <input type="radio" name="scope" value="all_users">
                        Alle Mitarbeiter
                    </label>
                </div>
            </fieldset>

            <div class="c-field">
                <label class="c-field__label" for="target_user_id">Mitarbeiter (für „Einzelner Mitarbeiter")</label>
                <select class="c-field__input" id="target_user_id" name="target_user_id">
                    <option value="">– Bitte wählen –</option>
                    <?php foreach ($users as $u): ?>
                    <option value="<?= $esc($u['id']) ?>">
                        <?= $esc($u['display_name'] ?: $u['email']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>

            <div class="l-cluster">
                <button
                    class="c-btn c-btn--primary"
                    type="submit"
                    formaction="/export/csv"
                >CSV herunterladen</button>
                <button
                    class="c-btn c-btn--secondary"
                    type="submit"
                    formaction="/export/pdf"
                >PDF herunterladen</button>
            </div>
        </form>
    </div>
</div>
