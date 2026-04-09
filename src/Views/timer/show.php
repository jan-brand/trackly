<?php

declare(strict_types=1);

use App\Security\Csrf;

/** @var array<string, mixed>|null $timerSession */

$status = $timerSession['status'] ?? 'idle';
?>
<div class="l-section">
    <div class="l-wrapper">
        <div class="l-stack">
            <h1>Timer</h1>

            <?php if ($timerSession === null): ?>
                <p class="u-muted">Bereit</p>
                <form method="post" action="/timer/start">
                    <?= Csrf::inputHtml() ?>
                    <button type="submit" class="c-btn c-btn--primary">Start</button>
                </form>
            <?php elseif ($status === 'running'): ?>
                <p class="is-running">Läuft</p>
                <div class="l-cluster">
                    <form method="post" action="/timer/pause">
                        <?= Csrf::inputHtml() ?>
                        <button type="submit" class="c-btn">Pause</button>
                    </form>
                    <form method="post" action="/timer/stop">
                        <?= Csrf::inputHtml() ?>
                        <button type="submit" class="c-btn c-btn--danger">Stop</button>
                    </form>
                </div>
            <?php else: ?>
                <p class="is-paused">Pausiert</p>
                <div class="l-cluster">
                    <form method="post" action="/timer/resume">
                        <?= Csrf::inputHtml() ?>
                        <button type="submit" class="c-btn c-btn--primary">Weiter</button>
                    </form>
                    <form method="post" action="/timer/stop">
                        <?= Csrf::inputHtml() ?>
                        <button type="submit" class="c-btn c-btn--danger">Stop</button>
                    </form>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
