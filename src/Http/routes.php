<?php

declare(strict_types=1);

use App\Controllers\AdminController;
use App\Controllers\AuthController;
use App\Controllers\HomeController;
use App\Controllers\TimeEntryController;
use App\Controllers\TimerController;
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

// -------------------------------------------------------------------------
// Time entries (employee self-service)
// -------------------------------------------------------------------------

$router->get('/time-entries', function (): Response {
    return (new TimeEntryController())->index();
});

$router->get('/time-entries/new', function (): Response {
    return (new TimeEntryController())->newForm();
});

$router->post('/time-entries', function (): Response {
    return (new TimeEntryController())->create();
});

$router->get('/time-entries/:id', function (): Response {
    return (new TimeEntryController())->show();
});

$router->post('/time-entries/:id/cancel', function (): Response {
    return (new TimeEntryController())->cancel();
});

$router->post('/time-entries/:id', function (): Response {
    return (new TimeEntryController())->update();
});

// -------------------------------------------------------------------------
// Timer (employee self-service)
// -------------------------------------------------------------------------

$router->get('/timer', function (): Response {
    return (new TimerController())->index();
});

$router->post('/timer/start', function (): Response {
    return (new TimerController())->start();
});

$router->post('/timer/pause', function (): Response {
    return (new TimerController())->pause();
});

$router->post('/timer/resume', function (): Response {
    return (new TimerController())->resume();
});

$router->post('/timer/stop', function (): Response {
    return (new TimerController())->stop();
});

// -------------------------------------------------------------------------
// Dev/test helpers
// -------------------------------------------------------------------------

$router->get('/boom', function (): Response {
    throw new \RuntimeException('Boom! Test exception.');
});

$router->get('/demo/bad-request', function (): Response {
    throw new BadRequestException('Unknown query parameter.');
});

