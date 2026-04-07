<?php

declare(strict_types=1);

use App\Controllers\AdminController;
use App\Controllers\AuthController;
use App\Controllers\HomeController;
use App\Http\BadRequestException;
use App\Http\Response;
use App\Http\Router;

/** @var Router $router */

$router->get('/health', function (): Response {
    return new Response(200, ['Content-Type' => 'text/plain; charset=utf-8'], 'OK');
});

$router->get('/', function (): Response {
    return (new HomeController())->index();
});

$router->get('/login', function (): Response {
    return (new AuthController())->showLogin();
});

$router->post('/login', function (): Response {
    return (new AuthController())->doLogin();
});

$router->post('/logout', function (): Response {
    return (new AuthController())->doLogout();
});

$router->get('/admin/settings', function (): Response {
    return (new AdminController())->settings();
});

$router->post('/admin/settings', function (): Response {
    return (new AdminController())->saveSettings();
});

$router->get('/boom', function (): Response {
    throw new \RuntimeException('Boom! Test exception.');
});

$router->get('/demo/bad-request', function (): Response {
    throw new BadRequestException('Unknown query parameter.');
});
