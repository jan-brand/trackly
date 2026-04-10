<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Trackly Export <?= htmlspecialchars($month ?? '', ENT_QUOTES, 'UTF-8') ?></title>
    <style>
        body { font-family: Arial, sans-serif; font-size: 11pt; margin: 2cm; }
        h1 { font-size: 14pt; margin-bottom: 0.5em; }
        table { width: 100%; border-collapse: collapse; margin-top: 1em; }
        th, td { border: 1px solid #aaa; padding: 4px 8px; text-align: left; }
        th { background: #eee; font-weight: bold; }
        tr:nth-child(even) { background: #f9f9f9; }
    </style>
</head>
<body>
<?php
declare(strict_types=1);

/**
 * @var string                         $month  YYYY-MM
 * @var list<array<string, mixed>>     $rows   Approved time entry rows
 */

$esc = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

$formatTime = static function (string $dt): string {
    if (strlen($dt) > 5) {
        return substr($dt, 11, 5);
    }
    return $dt;
};
?>
<h1>Zeiterfassung – <?= $esc($month) ?></h1>

<table>
    <thead>
        <tr>
            <th>Datum</th>
            <th>Mitarbeiter</th>
            <th>Beginn</th>
            <th>Ende</th>
            <th>Pause (Min)</th>
            <th>Netto (Min)</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($rows as $row): ?>
        <tr>
            <td><?= $esc($row['date_local'] ?? '') ?></td>
            <td><?= $esc($row['display_name'] ?? '') ?></td>
            <td><?= $esc($formatTime($row['start_at'] ?? '')) ?></td>
            <td><?= $esc($formatTime($row['end_at'] ?? '')) ?></td>
            <td><?= $esc((string) ($row['break_minutes'] ?? 0)) ?></td>
            <td><?= $esc((string) ($row['net_minutes'] ?? 0)) ?></td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>
</body>
</html>
