<?php

declare(strict_types=1);

use App\Support\Flash;

$flash = Flash::consume();
$title = $title ?? 'Trackly';
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></title>
</head>
<body>
<?php if (!empty($flash['success'])): ?>
<section class="flash flash-success">
    <h2>OK</h2>
    <ul>
    <?php foreach ($flash['success'] as $msg): ?>
        <li><?= htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') ?></li>
    <?php endforeach; ?>
    </ul>
</section>
<?php endif; ?>
<?php if (!empty($flash['error'])): ?>
<section class="flash flash-error">
    <h2>Fehler</h2>
    <ul>
    <?php foreach ($flash['error'] as $msg): ?>
        <li><?= htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') ?></li>
    <?php endforeach; ?>
    </ul>
</section>
<?php endif; ?>
<main id="main">
<?= $content ?? '' ?>
</main>
</body>
</html>
