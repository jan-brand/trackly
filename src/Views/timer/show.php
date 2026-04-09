<?php

declare(strict_types=1);

use App\Security\Csrf;

/** @var array<string, mixed>|null $timerSession */

$status = $timerSession['status'] ?? 'idle';

// Compute the number of net elapsed seconds to seed the JS clock.
// Running : (now − started_at) − total_pause_seconds  →  clock ticks live
// Paused  : (paused_at − started_at) − total_pause_seconds  →  clock is frozen
$seedSeconds = 0;
$clockRunning = false;
if ($timerSession !== null) {
    $startedTs      = (int) strtotime((string) $timerSession['started_at']);
    $totalPauseSecs = (int) $timerSession['total_pause_seconds'];
    if ($status === 'running') {
        $seedSeconds  = max(0, time() - $startedTs - $totalPauseSecs);
        $clockRunning = true;
    } else {
        // paused – freeze at the moment we paused
        $pausedTs    = (int) strtotime((string) ($timerSession['paused_at'] ?? $timerSession['started_at']));
        $seedSeconds = max(0, $pausedTs - $startedTs - $totalPauseSecs);
    }
}
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
                <p class="u-text-xl u-tabular-nums">
                    <span id="timer-display" aria-live="off" aria-atomic="true">--:--:--</span>
                </p>
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
                <p class="u-text-xl u-tabular-nums">
                    <span id="timer-display" aria-live="off" aria-atomic="true">--:--:--</span>
                </p>
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

<?php if ($timerSession !== null): ?>
<script>
(function () {
    'use strict';

    var el      = document.getElementById('timer-display');
    if (!el) return;

    var seconds  = <?= (int) $seedSeconds ?>;
    var ticking  = <?= $clockRunning ? 'true' : 'false' ?>;

    function pad(n) { return n < 10 ? '0' + n : '' + n; }

    function render() {
        var h = Math.floor(seconds / 3600);
        var m = Math.floor((seconds % 3600) / 60);
        var s = seconds % 60;
        el.textContent = pad(h) + ':' + pad(m) + ':' + pad(s);
    }

    render();

    if (ticking) {
        setInterval(function () {
            seconds += 1;
            render();
        }, 1000);
    }
}());
</script>
<?php endif; ?>
