<?php

declare(strict_types=1);

/** @var list<array<string, mixed>> $employees */

$title = $title ?? 'Mitarbeitende - Koordination - Trackly';

$esc = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

$displayName = static function (array $row): string {
    $first = trim((string) ($row['first_name'] ?? ''));
    $last = trim((string) ($row['last_name'] ?? ''));
    $combined = trim($first . ' ' . $last);

    if ($combined !== '') {
        return $combined;
    }

    $stored = trim((string) ($row['display_name'] ?? ''));
    if ($stored !== '') {
        return $stored;
    }

    return (string) ($row['email'] ?? '');
};
?>
<div class="l-section">
    <div class="l-wrapper">
        <h1 class="u-mb-4">Mitarbeitende und Konten</h1>

        <?php if (empty($employees)): ?>
            <p>Keine Mitarbeitenden-Konten vorhanden.</p>
        <?php else: ?>
            <table class="c-table" style="width:100%">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>E-Mail</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($employees as $row): ?>
                        <tr>
                            <td><?= $esc($displayName($row)) ?></td>
                            <td><?= $esc($row['email']) ?></td>
                            <td><?= ((int) ($row['is_active'] ?? 0) === 1) ? 'Aktiv' : 'Inaktiv' ?></td>
                            <td><a class="c-btn c-btn--secondary c-btn--sm" href="/coordination/employees/<?= $esc($row['id']) ?>">Bearbeiten</a></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>
