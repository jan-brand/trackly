<?php

declare(strict_types=1);

use App\Security\Auth;

$title = $title ?? 'Übersicht';
$loggedIn = Auth::isLoggedIn();
$isAdmin = $loggedIn && Auth::hasRole('admin');

$recentEntries = $recentEntries ?? [];
$announcements = $announcements ?? [];
$stats = $stats ?? ['today' => null, 'week' => null, 'total' => null];
?>

<div class="l-section">
    <div class="l-wrapper">
        <div class="l-stack">
            <div class="l-cluster l-cluster--space">
                <div>
                    <h1>Übersicht</h1>
                    <p class="u-text-muted">Schnelle Übersicht & Aktionen</p>
                </div>
                <div class="l-cluster">
                    <?php if ($loggedIn): ?>
                        <a class="c-btn c-btn--primary" href="/timer">Timer starten</a>
                        <a class="c-btn c-btn--secondary" href="/time-entries/new">Neuer Eintrag</a>
                        <a class="c-btn c-btn--secondary" href="/time-entries">Alle Einträge</a>
                    <?php else: ?>
                        <a class="c-btn c-btn--primary" href="/login">Anmelden</a>
                    <?php endif; ?>
                </div>
            </div>

            <div class="l-grid">
                <div class="c-card">
                    <h2>Letzte Einträge</h2>
                    <?php if (empty($recentEntries)): ?>
                        <p class="u-text-muted">Keine aktuellen Einträge vorhanden.</p>
                    <?php else: ?>
                        <div class="c-table-wrapper">
                            <table class="c-table c-table--compact">
                                <thead>
                                    <tr>
                                        <th>Datum</th>
                                        <th>Mitarbeiter</th>
                                        <th>Dauer</th>
                                        <th>Begründung</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($recentEntries as $e): ?>
                                    <tr>
                                        <td><?= htmlspecialchars((string) ($e['date'] ?? '')) ?></td>
                                        <td><?= htmlspecialchars((string) ($e['user_name'] ?? ($e['email'] ?? ''))) ?></td>
                                        <td><?= htmlspecialchars((string) ($e['duration'] ?? '')) ?></td>
                                        <td><?= htmlspecialchars((string) ($e['reason'] ?? '')) ?></td>
                                        <td><a class="c-btn c-btn--secondary c-btn--sm" href="/time-entries/<?= htmlspecialchars((string) ($e['id'] ?? '')) ?>">Ansehen</a></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>

                <div>
                    <div class="c-card u-mb-4">
                        <h3>Schnellstatistiken</h3>
                        <div class="l-grid">
                            <div class="c-card l-center">
                                <div>
                                    <div class="u-text-2xl"><?= htmlspecialchars((string) ($stats['today'] ?? '–')) ?></div>
                                    <div class="u-text-muted">Heute</div>
                                </div>
                            </div>
                            <div class="c-card l-center">
                                <div>
                                    <div class="u-text-2xl"><?= htmlspecialchars((string) ($stats['week'] ?? '–')) ?></div>
                                    <div class="u-text-muted">Diese Woche</div>
                                </div>
                            </div>
                            <div class="c-card l-center">
                                <div>
                                    <div class="u-text-2xl"><?= htmlspecialchars((string) ($stats['total'] ?? '–')) ?></div>
                                    <div class="u-text-muted">Gesamt</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="c-card">
                        <h3>Ankündigungen</h3>
                        <?php if (empty($announcements)): ?>
                            <p class="u-text-muted">Keine aktuellen Ankündigungen.</p>
                        <?php else: ?>
                            <ul>
                                <?php foreach ($announcements as $a): ?>
                                    <li><a href="/announcements/<?= htmlspecialchars((string) ($a['id'] ?? '')) ?>"><?= htmlspecialchars((string) ($a['title'] ?? '')) ?></a></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
