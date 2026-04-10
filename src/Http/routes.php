<?php

declare(strict_types=1);

use App\Controllers\AdminController;
use App\Controllers\AnnouncementController;
use App\Controllers\AuthController;
use App\Controllers\ClarificationController;
use App\Controllers\CoordinationController;
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
// Announcements (employee self-service)
// -------------------------------------------------------------------------

$router->get('/announcements', function (): Response {
    return (new AnnouncementController())->index();
});

$router->get('/announcements/new', function (): Response {
    return (new AnnouncementController())->newForm();
});

$router->post('/announcements', function (): Response {
    return (new AnnouncementController())->create();
});

$router->get('/announcements/:id', function (): Response {
    return (new AnnouncementController())->show();
});

$router->post('/announcements/:id', function (): Response {
    return (new AnnouncementController())->update();
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
// Coordination queue
// -------------------------------------------------------------------------

$router->get('/coordination/queue', function (): Response {
    return (new CoordinationController())->queue();
});

$router->get('/coordination/time-entries/:id', function (): Response {
    return (new CoordinationController())->show();
});

$router->post('/coordination/time-entries/:id/approve', function (): Response {
    return (new CoordinationController())->approve();
});

$router->post('/coordination/time-entries/:id/request-clarification', function (): Response {
    return (new CoordinationController())->requestClarification();
});

// -------------------------------------------------------------------------
// Clarifications
// -------------------------------------------------------------------------

$router->get('/clarifications', function (): Response {
    return (new ClarificationController())->index();
});

$router->post('/clarifications/:id/answer', function (): Response {
    return (new ClarificationController())->answer();
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

