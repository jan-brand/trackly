<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

/**
 * Simulate an HTTP request through public/index.php and capture
 * the output, response code, and headers.
 *
 * @param  string $method  HTTP method (GET, POST, …)
 * @param  string $uri     Request URI (e.g. "/health")
 * @param  array<string, string> $post  POST body fields
 * @param  array<string, string> $server  Additional $_SERVER overrides
 * @return array{status: int, headers: array<string, string>, body: string}
 */
function simulateRequest(
    string $method,
    string $uri,
    array $post = [],
    array $server = [],
): array {
    // Reset superglobals
    $_SERVER = array_merge([
        'REQUEST_METHOD' => strtoupper($method),
        'REQUEST_URI'    => $uri,
        'HTTP_HOST'      => 'localhost',
        'SCRIPT_FILENAME' => dirname(__DIR__) . '/public/index.php',
    ], $server);

    $_POST    = $post;
    $_GET     = [];
    $_SESSION = [];
    $_ENV     = $_ENV ?? [];

    // Capture headers via a custom header list
    // We override http_response_code and header() calls by capturing output
    // and reading the global state.
    $capturedStatus  = 200;
    $capturedHeaders = [];

    // Use output buffering to capture the response body
    ob_start();

    // We need to run index.php in a way we can capture the status code.
    // We instrument by wrapping the send call.
    // Strategy: include public/index.php but intercept Response::send().
    // Since we can't easily mock that, we re-implement the dispatch inline.

    // Load env
    \App\Support\Env::load(dirname(__DIR__) . '/.env');

    // Build router
    $router = new \App\Http\Router();
    require dirname(__DIR__) . '/src/Http/routes.php';

    $reqMethod = $_SERVER['REQUEST_METHOD'];
    $reqPath   = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/';

    try {
        $response = $router->dispatch($reqMethod, $reqPath);
    } catch (\App\Http\BadRequestException) {
        $body = renderViewTest('errors/400');
        $response = new \App\Http\Response(400, ['Content-Type' => 'text/html; charset=utf-8'], $body);
    } catch (\App\Security\CsrfViolationException) {
        $body = renderViewTest('errors/403');
        $response = new \App\Http\Response(403, ['Content-Type' => 'text/html; charset=utf-8'], $body);
    } catch (\Throwable) {
        $body = renderViewTest('errors/500');
        $response = new \App\Http\Response(500, ['Content-Type' => 'text/html; charset=utf-8'], $body);
    }

    ob_end_clean();

    return [
        'status'  => $response->statusCode,
        'headers' => $response->headers,
        'body'    => $response->body,
    ];
}

/**
 * Render a view without using real sessions (for test context).
 */
function renderViewTest(string $view, array $data = []): string
{
    $viewPath = dirname(__DIR__) . '/src/Views/' . $view . '.php';
    extract($data);
    ob_start();
    require $viewPath;
    $content = ob_get_clean();
    ob_start();
    require dirname(__DIR__) . '/src/Views/layout.php';
    return ob_get_clean();
}

// Make renderView available globally for the routes (mirrors public/index.php)
if (!function_exists('renderView')) {
    function renderView(string $view, array $data = []): string
    {
        return renderViewTest($view, $data);
    }
}
