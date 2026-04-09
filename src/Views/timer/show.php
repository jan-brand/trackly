<?php

declare(strict_types=1);

use App\Security\Csrf;

/** @var array<string, mixed>|null $timerSession */

$status = $timerSession['status'] ?? 'idle';

// Compute seconds to seed the JS clocks.
//
// Work clock:
//   Running : (now − started_at) − total_pause_seconds  →  ticks live
//   Paused  : (paused_at − started_at) − total_pause_seconds  →  frozen
//
// Pause clock:
//   Running : total_pause_seconds  →  frozen (no new pause accumulating)
//   Paused  : total_pause_seconds + (now − paused_at)  →  ticks live
$seedSeconds      = 0;
$clockRunning     = false;
$pauseSeedSeconds = 0;
$pauseTicking     = false;
if ($timerSession !== null) {
    $startedTs      = (int) strtotime((string) $timerSession['started_at']);
    $totalPauseSecs = (int) $timerSession['total_pause_seconds'];
    if ($status === 'running') {
        $seedSeconds  = max(0, time() - $startedTs - $totalPauseSecs);
        $clockRunning = true;
        $pauseSeedSeconds = $totalPauseSecs;
    } else {
        // paused – work clock freezes, pause clock ticks
        $pausedTs    = (int) strtotime((string) ($timerSession['paused_at'] ?? $timerSession['started_at']));
        $seedSeconds = max(0, $pausedTs - $startedTs - $totalPauseSecs);
        $pauseSeedSeconds = $totalPauseSecs + max(0, time() - $pausedTs);
        $pauseTicking     = true;
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
                <p class="u-text-xl" style="font-variant-numeric: tabular-nums;">
                    <span id="timer-display" aria-live="off" aria-atomic="true">--:--:--</span>
                </p>
                <p class="u-text-sm u-text-muted">
                    Pause: <span id="pause-display" style="font-variant-numeric: tabular-nums;">--:--:--</span>
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
                <p class="u-text-xl" style="font-variant-numeric: tabular-nums;">
                    <span id="timer-display" aria-live="off" aria-atomic="true">--:--:--</span>
                </p>
                <p class="u-text-sm u-text-muted">
                    Pause: <span id="pause-display" style="font-variant-numeric: tabular-nums;">--:--:--</span>
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

    var workEl  = document.getElementById('timer-display');
    var pauseEl = document.getElementById('pause-display');
    if (!workEl || !pauseEl) return;

    var workSecs   = <?= (int) $seedSeconds ?>;
    var pauseSecs  = <?= (int) $pauseSeedSeconds ?>;
    var workTicks  = <?= $clockRunning ? 'true' : 'false' ?>;
    var pauseTicks = <?= $pauseTicking ? 'true' : 'false' ?>;

    function pad(n) { return n < 10 ? '0' + n : '' + n; }

    function fmt(s) {
        var h = Math.floor(s / 3600);
        var m = Math.floor((s % 3600) / 60);
        var sec = s % 60;
        return pad(h) + ':' + pad(m) + ':' + pad(sec);
    }

    function render() {
        workEl.textContent  = fmt(workSecs);
        pauseEl.textContent = fmt(pauseSecs);
    }

    render();

    if (workTicks || pauseTicks) {
        setInterval(function () {
            if (workTicks)  workSecs  += 1;
            if (pauseTicks) pauseSecs += 1;
            render();
        }, 1000);
    }
}());
</script>
<?php endif; ?>
