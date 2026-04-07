<?php

declare(strict_types=1);

use App\Security\Csrf;

$title = 'Anmelden – Trackly';
?>
<h1>Anmelden</h1>
<form method="post" action="/login">
    <?= Csrf::inputHtml() ?>
    <div>
        <label for="email">E-Mail</label>
        <input type="email" id="email" name="email" required autocomplete="username">
    </div>
    <div>
        <label for="password">Passwort</label>
        <input type="password" id="password" name="password" required autocomplete="current-password">
    </div>
    <button type="submit">Anmelden</button>
</form>
