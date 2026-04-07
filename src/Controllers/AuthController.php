<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Db\Db;
use App\Http\Response;
use App\Security\Auth;
use App\Security\Csrf;
use App\Support\Flash;

class AuthController
{
    public function showLogin(): Response
    {
        $body = renderView('auth/login');
        return new Response(200, ['Content-Type' => 'text/html; charset=utf-8'], $body);
    }

    public function doLogin(): Response
    {
        Csrf::verifyOrFail();

        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        try {
            $pdo = Db::pdo();
            $stmt = $pdo->prepare('SELECT id, password_hash, is_active FROM users WHERE email = :email LIMIT 1');
            $stmt->execute([':email' => $email]);
            $user = $stmt->fetch();
        } catch (\Throwable) {
            $user = false;
        }

        if ($user === false
            || !password_verify($password, $user['password_hash'])
            || (int) $user['is_active'] !== 1
        ) {
            Flash::addError('E-Mail oder Passwort stimmt nicht.');
            $body = renderView('auth/login');
            return new Response(422, ['Content-Type' => 'text/html; charset=utf-8'], $body);
        }

        session_regenerate_id(true);
        $_SESSION['user_id'] = $user['id'];

        Flash::addSuccess('Angemeldet.');
        return new Response(303, ['Location' => Auth::defaultHome()], '');
    }

    public function doLogout(): Response
    {
        Csrf::verifyOrFail();

        Auth::clearRoleCache();
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly'],
            );
        }

        session_destroy();

        // Start a fresh session to carry the flash message across the redirect.
        session_start();
        Flash::addSuccess('Abgemeldet.');

        return new Response(303, ['Location' => '/login'], '');
    }
}
