<?php

declare(strict_types=1);

use App\Security\Auth;
use App\Support\Flash;

$flash   = Flash::consume();
$title   = $title ?? 'Trackly';
$hideNav = $hideNav ?? false;

$loggedIn    = Auth::isLoggedIn();
$isAdminOrCo = $loggedIn && Auth::hasAnyRole(['admin', 'coordination']);
$isTreasurer = $loggedIn && Auth::hasRole('treasurer');
$isEmployee  = $loggedIn && Auth::hasRole('employee');
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body class="l-page">
<a class="l-skip-link" href="#main">Zum Inhalt springen</a>
<?php if (!$hideNav): ?>
<header class="l-header">
    <nav class="c-nav l-wrapper" aria-label="Hauptnavigation">
        <a class="c-nav__brand" href="/">Trackly</a>
        <ul class="c-nav__links" role="list">
            <?php if ($isEmployee): ?>
                <li><a class="c-nav__link" href="/timer">Meine Zeiten</a></li>
            <?php endif; ?>
            <?php if ($isAdminOrCo): ?>
                <li><a class="c-nav__link" href="/coordination/queue">Warteschlange</a></li>
                <li><a class="c-nav__link" href="/admin/settings">Einstellungen</a></li>
            <?php endif; ?>
            <?php if ($isTreasurer): ?>
                <li><a class="c-nav__link" href="/export">Export</a></li>
            <?php endif; ?>
            <?php if (!$loggedIn): ?>
                <li><a class="c-nav__link" href="/">Übersicht</a></li>
            <?php endif; ?>
        </ul>
        <div class="c-nav__actions">
            <?php if ($loggedIn): ?>
            <form method="post" action="/logout" style="display:inline">
                <?= \App\Security\Csrf::inputHtml() ?>
                <button class="c-btn c-btn--secondary c-btn--sm" type="submit">Abmelden</button>
            </form>
            <?php else: ?>
            <a class="c-btn c-btn--secondary c-btn--sm" href="/login">Anmelden</a>
            <?php endif; ?>
        </div>
    </nav>
</header>
<?php endif; ?>
<main id="main">
<?php if (!empty($flash['success'])): ?>
<div class="l-wrapper u-mt-4" role="status" aria-live="polite">
    <div class="c-flash c-flash--success">
        <span class="c-flash__icon" aria-hidden="true">✓</span>
        <div class="c-flash__body">
            <p class="c-flash__title">Erfolgreich</p>
            <ul>
            <?php foreach ($flash['success'] as $msg): ?>
                <li><?= htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') ?></li>
            <?php endforeach; ?>
            </ul>
        </div>
    </div>
</div>
<?php endif; ?>
<?php if (!empty($flash['error'])): ?>
<div class="l-wrapper u-mt-4" role="alert" aria-live="assertive">
    <div class="c-flash c-flash--error">
        <span class="c-flash__icon" aria-hidden="true">✕</span>
        <div class="c-flash__body">
            <p class="c-flash__title">Fehler</p>
            <ul>
            <?php foreach ($flash['error'] as $msg): ?>
                <li><?= htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') ?></li>
            <?php endforeach; ?>
            </ul>
        </div>
    </div>
</div>
<?php endif; ?>
<?= $content ?? '' ?>
</main>
</body>
</html>
