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
$isAdmin     = $loggedIn && Auth::hasRole('admin');
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body class="l-page sidebar-compact">
<a class="l-skip-link" href="#main">Zum Inhalt springen</a>
<?php /* Topbar removed — navigation moved into persistent sidebar */ ?>
<main id="main">
    <div class="l-wrapper l-sidebar">
        <?php if (!$hideNav): ?>
        <aside id="siteSidebar" class="l-sidebar__aside" aria-label="Seitennavigation">
            <button id="sidebarToggle" class="c-sidebar-toggle" aria-label="Seitenmenü ein-/ausklappen" aria-controls="siteSidebar" aria-expanded="false" title="Seitenmenü">
                <span class="icon-open" aria-hidden="true">
                    <svg viewBox="0 0 24 24" width="18" height="18" focusable="false"><path d="M3 6h18M3 12h18M3 18h18" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" fill="none"></path></svg>
                </span>
                <span class="icon-close" aria-hidden="true">
                    <svg viewBox="0 0 24 24" width="18" height="18" focusable="false"><path d="M18 6L6 18M6 6l12 12" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" fill="none"></path></svg>
                </span>
            </button>
            <nav class="c-nav c-nav--sidebar" aria-label="Seitenmenü">
                <div class="c-nav__brand-wrap">
                    <a class="c-nav__brand" href="/">Trackly</a>
                </div>

                <div class="c-nav__section" aria-labelledby="nav-main-title">
                    <div class="c-nav__section-title" id="nav-main-title">Haupt</div>
                    <ul class="c-nav__links" role="list">
                        <?php if ($isEmployee): ?>
                            <li>
                                <a class="c-nav__link" href="/timer" title="Meine Zeiten">
                                    <span class="c-nav__icon" aria-hidden="true"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"></circle><path d="M12 7v5l3 2"></path></svg></span>
                                    <span class="c-nav__label">Meine Zeiten</span>
                                </a>
                            </li>
                            <li>
                                <a class="c-nav__link" href="/profile" title="Mein Profil">
                                    <span class="c-nav__icon" aria-hidden="true"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg></span>
                                    <span class="c-nav__label">Mein Profil</span>
                                </a>
                            </li>
                            <li>
                                <a class="c-nav__link" href="/time-entries" title="Zeiteinträge">
                                    <span class="c-nav__icon" aria-hidden="true"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><path d="M16 2v4"></path></svg></span>
                                    <span class="c-nav__label">Zeiteinträge</span>
                                </a>
                            </li>
                        <?php endif; ?>
                        <?php if ($loggedIn): ?>
                            <li>
                                <a class="c-nav__link" href="/announcements" title="Ankündigungen">
                                    <span class="c-nav__icon" aria-hidden="true"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 12h-6"></path><path d="M6 12H2"></path><path d="M12 2v6"></path></svg></span>
                                    <span class="c-nav__label">Ankündigungen</span>
                                </a>
                            </li>
                        <?php endif; ?>
                        <?php if (!$loggedIn): ?>
                            <li>
                                <a class="c-nav__link" href="/" title="Übersicht">
                                    <span class="c-nav__icon" aria-hidden="true"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9.5L12 3l9 6.5V21a1 1 0 0 1-1 1h-5v-7H9v7H4a1 1 0 0 1-1-1V9.5z"></path></svg></span>
                                    <span class="c-nav__label">Übersicht</span>
                                </a>
                            </li>
                        <?php endif; ?>
                    </ul>
                </div>

                <?php if ($isAdmin || $isAdminOrCo): ?>
                <div class="c-nav__section" aria-labelledby="nav-coordination-title">
                    <div class="c-nav__section-title" id="nav-coordination-title">Koordination</div>
                    <ul class="c-nav__links" role="list">
                        <li>
                            <a class="c-nav__link" href="/coordination/queue" title="Warteschlange">
                                <span class="c-nav__icon" aria-hidden="true"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"></path></svg></span>
                                <span class="c-nav__label">Warteschlange</span>
                            </a>
                        </li>
                        <li>
                            <a class="c-nav__link" href="/coordination/employees" title="Mitarbeitende">
                                <span class="c-nav__icon" aria-hidden="true"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-3-3.87"></path><path d="M7 21v-2a4 4 0 0 1 3-3.87"></path><circle cx="9" cy="7" r="4"></circle><circle cx="17" cy="7" r="4"></circle></svg></span>
                                <span class="c-nav__label">Mitarbeitende</span>
                            </a>
                        </li>
                        <li>
                            <a class="c-nav__link" href="/coordination/time_entries" title="Koordination: Zeiteinträge">
                                <span class="c-nav__icon" aria-hidden="true"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><path d="M16 2v4"></path></svg></span>
                                <span class="c-nav__label">Zeiteinträge (Koordination)</span>
                            </a>
                        </li>
                    </ul>
                </div>
                <?php endif; ?>

                <?php if ($isAdminOrCo || $isTreasurer): ?>
                <div class="c-nav__section" aria-labelledby="nav-admin-title">
                    <div class="c-nav__section-title" id="nav-admin-title">Administration</div>
                    <ul class="c-nav__links" role="list">
                        <?php if ($isAdminOrCo): ?>
                            <li>
                                <a class="c-nav__link" href="/coordination/queue" title="Warteschlange">
                                    <span class="c-nav__icon" aria-hidden="true"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"></path></svg></span>
                                    <span class="c-nav__label">Warteschlange</span>
                                </a>
                            </li>
                            <li>
                                <a class="c-nav__link" href="/coordination/employees" title="Mitarbeitende">
                                    <span class="c-nav__icon" aria-hidden="true"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-3-3.87"></path><path d="M7 21v-2a4 4 0 0 1 3-3.87"></path><circle cx="9" cy="7" r="4"></circle><circle cx="17" cy="7" r="4"></circle></svg></span>
                                    <span class="c-nav__label">Mitarbeitende</span>
                                </a>
                            </li>
                            <li>
                                <a class="c-nav__link" href="/admin/settings" title="Einstellungen">
                                    <span class="c-nav__icon" aria-hidden="true"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 15.5A3.5 3.5 0 1 0 12 8.5a3.5 3.5 0 0 0 0 7z"></path><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33"></path></svg></span>
                                    <span class="c-nav__label">Einstellungen</span>
                                </a>
                            </li>
                        <?php endif; ?>
                        <?php if ($isAdminOrCo || $isTreasurer): ?>
                            <li>
                                <a class="c-nav__link" href="/export" title="Export">
                                    <span class="c-nav__icon" aria-hidden="true"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v12"></path><path d="M8 7l4-4 4 4"></path><path d="M21 21H3"></path></svg></span>
                                    <span class="c-nav__label">Export</span>
                                </a>
                            </li>
                        <?php endif; ?>
                    </ul>
                </div>
                <?php endif; ?>

                <div class="c-nav__account" role="region" aria-label="Konto">
                    <?php if ($loggedIn): ?>
                        <div class="c-nav__account-card">
                            <a href="/profile" class="c-nav__account-link" title="Mein Profil">
                                <span class="c-nav__account-icon" aria-hidden="true">
                                    <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
                                </span>
                                <span class="c-nav__account-meta">
                                    <span class="c-nav__account-title">Mein Profil</span>
                                    <span class="c-nav__account-sub">Account & Einstellungen</span>
                                </span>
                            </a>
                            <div class="c-nav__account-actions">
                                <a href="/profile" class="c-btn c-btn--tertiary">Profil</a>
                                <a href="/settings" class="c-btn c-btn--tertiary">Einstellungen</a>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="c-nav__account-card">
                            <a href="/login" class="c-btn c-btn--primary c-nav__account-login">Anmelden</a>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="c-nav__spacer" aria-hidden="true"></div>

                <div class="c-nav__section">
                    <div class="c-nav__links" role="list">
                        <?php if ($loggedIn): ?>
                            <div class="c-nav__logout">
                                <form method="post" action="/logout">
                                    <?= \App\Security\Csrf::inputHtml() ?>
                                    <button class="c-btn c-btn--tertiary" type="submit" title="Abmelden">
                                        <span class="c-nav__icon" aria-hidden="true"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path><path d="M16 17l5-5-5-5"></path><path d="M21 12H9"></path></svg></span>
                                        <span class="c-nav__label">Abmelden</span>
                                    </button>
                                </form>
                            </div>
                        <?php else: ?>
                            <a class="c-nav__link" href="/login" title="Anmelden">
                                <span class="c-nav__icon" aria-hidden="true"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"></path><path d="M10 17l5-5-5-5"></path><path d="M21 12H9"></path></svg></span>
                                <span class="c-nav__label">Anmelden</span>
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </nav>
        </aside>
        <?php endif; ?>

        <div class="l-sidebar__main">
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
        </div>
    </div>
</main>
<script>
    (function(){
        var STORAGE_KEY = 'trackly.sidebarCompact';
        var btn = document.getElementById('sidebarToggle');
        if (!btn) return;

        var setAria = function(isCompact) {
            btn.setAttribute('aria-expanded', (!isCompact).toString());
        };

        // Temporarily disable sidebar transitions during initial state apply
        document.body.classList.add('disable-sidebar-transitions');

        // Initialize from localStorage if available
        try {
            var stored = localStorage.getItem(STORAGE_KEY);
        } catch (e) {
            var stored = null;
        }

        if (stored !== null) {
            var compact = stored === 'true';
            if (compact) {
                document.body.classList.add('sidebar-compact');
            } else {
                document.body.classList.remove('sidebar-compact');
            }
            setAria(compact);
        } else {
            // No stored preference — use current DOM state
            setAria(document.body.classList.contains('sidebar-compact'));
        }

        // Remove the temporary class after the first paint so transitions work for interactions
        window.requestAnimationFrame(function() {
            window.requestAnimationFrame(function() {
                document.body.classList.remove('disable-sidebar-transitions');
            });
        });

        btn.addEventListener('click', function(){
            var compactNow = document.body.classList.toggle('sidebar-compact');
            try { localStorage.setItem(STORAGE_KEY, compactNow ? 'true' : 'false'); } catch (e) {}
            setAria(compactNow);
        });
    }());
</script>
<footer>
    <div class="l-wrapper u-text-center u-mt-6 u-mb-4">
        <p>&copy; <?php echo date('Y'); ?> Trackly. Alle Rechte vorbehalten.</p>
    </div>
</footer>
</body>
</html>
