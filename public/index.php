<?php

declare(strict_types=1);

use App\Http\BadRequestException;
use App\Http\Response;
use App\Http\Router;
use App\Http\View\ViewRenderer;
use App\Security\Csrf;
use App\Security\CsrfViolationException;
use App\Support\Env;

require_once dirname(__DIR__) . '/vendor/autoload.php';

Env::load(dirname(__DIR__) . '/.env');

session_start();

$viewRenderer = new ViewRenderer(dirname(__DIR__) . '/src/Views');

/**
 * Render a view template and wrap it in the layout.
 *
 * @param string               $view  Relative path inside src/Views/ without .php extension
 * @param array<string, mixed> $data  Variables to extract into the view scope
 */
function renderView(string $view, array $data = []): string
{
    global $viewRenderer;
    return $viewRenderer->render($view, $data);
}

$router = new Router();
require dirname(__DIR__) . '/src/Http/routes.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path   = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';

try {
    $response = $router->dispatch($method, $path);
} catch (BadRequestException) {
    $body = renderView('errors/400');
    $response = new Response(400, ['Content-Type' => 'text/html; charset=utf-8'], $body);
} catch (CsrfViolationException) {
    $body = renderView('errors/403');
    $response = new Response(403, ['Content-Type' => 'text/html; charset=utf-8'], $body);
} catch (\Throwable) {
    $body = renderView('errors/500');
    $response = new Response(500, ['Content-Type' => 'text/html; charset=utf-8'], $body);
}

$response->send();
