<?php

declare(strict_types=1);

use App\Security\Csrf;

$title = 'Anmelden – Trackly';
?>
<div class="l-auth">
    <div class="l-auth__card">
        <div class="l-stack l-stack--lg">
            <div class="u-text-center">
                <a class="c-nav__brand" href="/">Trackly</a>
            </div>
            <h1 class="u-text-center u-text-2xl">Anmelden</h1>
            <form class="c-form" method="post" action="/login" novalidate>
                <?= Csrf::inputHtml() ?>
                <div class="c-form__group">
                    <label class="c-form__label c-form__label--required" for="email">E-Mail</label>
                    <input class="c-form__input" type="email" id="email" name="email"
                           required autocomplete="username">
                </div>
                <div class="c-form__group">
                    <label class="c-form__label c-form__label--required" for="password">Passwort</label>
                    <input class="c-form__input" type="password" id="password" name="password"
                           required autocomplete="current-password">
                </div>
                <div class="c-form__actions">
                    <button class="c-btn c-btn--primary c-btn--full" type="submit">Anmelden</button>
                </div>
            </form>
        </div>
    </div>
</div>
