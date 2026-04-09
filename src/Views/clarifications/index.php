<?php
declare(strict_types=1);

/** @var list<array<string, mixed>> $clarifications */

$title = $title ?? 'Rückfragen – Trackly';

$esc = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
?>
<div class="l-section">
    <div class="l-wrapper">
        <h1 class="u-mb-4">Rückfragen</h1>

        <?php if (empty($clarifications)): ?>
        <p class="u-text-muted">Keine Rückfragen vorhanden.</p>
        <?php else: ?>
        <div class="c-table-wrapper">
            <table class="c-table c-table--compact">
                <thead>
                <tr>
                    <th scope="col">Zeiteintrag</th>
                    <th scope="col">Frage</th>
                    <th scope="col">Status</th>
                    <th scope="col">Antwort</th>
                    <th scope="col">Gestellt am</th>
                    <th scope="col"></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($clarifications as $c): ?>
                <tr>
                    <td>
                        <a href="/time-entries/<?= $esc($c['time_entry_id']) ?>">
                            #<?= $esc($c['time_entry_id']) ?>
                        </a>
                    </td>
                    <td><?= $esc($c['question_text']) ?></td>
                    <td>
                        <span class="c-badge c-badge--<?= $esc($c['status']) ?>">
                            <?= $esc($c['status'] === 'open' ? 'Offen' : 'Beantwortet') ?>
                        </span>
                    </td>
                    <td><?= $c['answer_text'] !== null ? $esc($c['answer_text']) : '–' ?></td>
                    <td><?= $esc($c['created_at']) ?></td>
                    <td>
                        <?php if ($c['status'] === 'open'): ?>
                        <form method="post" action="/clarifications/<?= $esc($c['id']) ?>/answer">
                            <?= \App\Security\Csrf::inputHtml() ?>
                            <div class="c-form-group">
                                <textarea
                                    class="c-input"
                                    name="answer_text"
                                    rows="2"
                                    minlength="2"
                                    required
                                    placeholder="Antwort (min. 2 Zeichen)"
                                ></textarea>
                            </div>
                            <button class="c-btn c-btn--primary c-btn--sm" type="submit">Antworten</button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>
