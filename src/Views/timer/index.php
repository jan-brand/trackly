<?php

declare(strict_types=1);

use App\Security\Csrf;

/** @var array<string, mixed>|null $timerSession */
?>
<div class="l-section">
    <div class="l-wrapper">
        <div class="l-stack">
            <h1>Timer</h1>

            <?php if ($timerSession === null): ?>
                <p>Kein laufender Timer.</p>
                <form method="post" action="/timer/start">
                    <?= Csrf::inputHtml() ?>
                    <button type="submit" class="c-btn c-btn--primary">Timer starten</button>
                </form>
            <?php elseif ($timerSession['status'] === 'running'): ?>
                <p class="is-running">Timer läuft seit <?= htmlspecialchars($timerSession['started_at'], ENT_QUOTES, 'UTF-8') ?>.</p>
                <div class="l-cluster">
                    <form method="post" action="/timer/pause">
                        <?= Csrf::inputHtml() ?>
                        <button type="submit" class="c-btn">Pausieren</button>
                    </form>
                    <form method="post" action="/timer/stop">
                        <?= Csrf::inputHtml() ?>
                        <button type="submit" class="c-btn c-btn--danger">Stoppen</button>
                    </form>
                </div>
            <?php else: ?>
                <p>Timer pausiert.</p>
                <div class="l-cluster">
                    <form method="post" action="/timer/resume">
                        <?= Csrf::inputHtml() ?>
                        <button type="submit" class="c-btn c-btn--primary">Fortsetzen</button>
                    </form>
                    <form method="post" action="/timer/stop">
                        <?= Csrf::inputHtml() ?>
                        <button type="submit" class="c-btn c-btn--danger">Stoppen</button>
                    </form>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
