<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

// ---------------------------------------------------------------------------
// Internal helpers
// ---------------------------------------------------------------------------

/**
 * Render a view without using real sessions (for test context).
 */
function renderViewTest(string $view, array $data = []): string
{
    static $renderer = null;
    if ($renderer === null) {
        $renderer = new \App\Http\View\ViewRenderer(dirname(__DIR__) . '/src/Views');
    }
    return $renderer->render($view, $data);
}

// Make renderView available globally for the routes (mirrors public/index.php)
if (!function_exists('renderView')) {
    function renderView(string $view, array $data = []): string
    {
        return renderViewTest($view, $data);
    }
}

/**
 * Build a router, dispatch the request, and convert any well-known exception
 * into the appropriate error Response.
 *
 * Assumes $_SERVER, $_POST, and $_SESSION are already set up by the caller.
 */
function resolveResponse(): \App\Http\Response
{
    \App\Support\Env::load(dirname(__DIR__) . '/.env');

    $router = new \App\Http\Router();
    require dirname(__DIR__) . '/src/Http/routes.php';

    $method = $_SERVER['REQUEST_METHOD'];
    $path   = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/';

    try {
        return $router->dispatch($method, $path);
    } catch (\App\Http\BadRequestException) {
        return new \App\Http\Response(400, ['Content-Type' => 'text/html; charset=utf-8'], renderViewTest('errors/400'));
    } catch (\App\Http\ForbiddenException) {
        return new \App\Http\Response(403, ['Content-Type' => 'text/html; charset=utf-8'], renderViewTest('errors/403'));
    } catch (\App\Security\CsrfViolationException) {
        return new \App\Http\Response(403, ['Content-Type' => 'text/html; charset=utf-8'], renderViewTest('errors/403'));
    } catch (\Throwable) {
        return new \App\Http\Response(500, ['Content-Type' => 'text/html; charset=utf-8'], renderViewTest('errors/500'));
    }
}

// ---------------------------------------------------------------------------
// Public test-harness functions
// ---------------------------------------------------------------------------

/**
 * Simulate an HTTP request through public/index.php and capture
 * the output, response code, and headers.
 *
 * @param  string                  $method   HTTP method (GET, POST, …)
 * @param  string                  $uri      Request URI (e.g. "/health")
 * @param  array<string, string>   $post     POST body fields
 * @param  array<string, string>   $server   Additional $_SERVER overrides
 * @param  array<string, mixed>    $session  Initial session state for the request
 * @return array{status: int, headers: array<string, string>, body: string}
 */
function simulateRequest(
    string $method,
    string $uri,
    array $post = [],
    array $server = [],
    array $session = [],
): array {
    $_SERVER = array_merge([
        'REQUEST_METHOD'  => strtoupper($method),
        'REQUEST_URI'     => $uri,
        'HTTP_HOST'       => 'localhost',
        'SCRIPT_FILENAME' => dirname(__DIR__) . '/public/index.php',
    ], $server);

    $_POST    = $post;
    $_GET     = [];
    $_SESSION = $session;
    $_ENV     = $_ENV ?? [];

    ob_start();
    $response = resolveResponse();
    ob_end_clean();

    return [
        'status'  => $response->statusCode,
        'headers' => $response->headers,
        'body'    => $response->body,
    ];
}

/**
 * Dispatch an HTTP request through the application router and capture the
 * response without relying on a real HTTP connection.
 *
 * Status and headers are captured via http_response_code() and headers_list()
 * after calling Response::send() inside an output buffer.
 * The session state at the end of the request is returned as 'session'.
 *
 * @param  string               $method   HTTP method (GET, POST, …)
 * @param  string               $path     Request path (e.g. "/health")
 * @param  array<string,string> $post     POST body fields
 * @param  array<string,mixed>  $session  Initial session state for this request
 * @return array{status: int, headers: array<string,string>, body: string, session: array<string,mixed>}
 */
function dispatch(string $method, string $path, array $post = [], array $session = []): array
{
    if (class_exists('App\\Support\\EmailQueue')) {
        \App\Support\EmailQueue::clear();
    }

    // Snapshot globals so they can be restored after the request.
    $prevServer = $_SERVER;

    $_SERVER['REQUEST_METHOD']  = strtoupper($method);
    $_SERVER['REQUEST_URI']     = $path;
    $_SERVER['HTTP_HOST']       = $prevServer['HTTP_HOST'] ?? 'localhost';
    $_SERVER['SCRIPT_FILENAME'] = dirname(__DIR__) . '/public/index.php';

    $_POST    = $post;
    $_GET     = [];
    $_SESSION = $session;
    $_ENV     = $_ENV ?? [];

    $response = resolveResponse();

    // Clear any headers queued by earlier requests, then call send() so that
    // http_response_code() and headers_list() reflect *this* response only.
    header_remove();
    ob_start();
    $response->send();
    $body = (string) ob_get_clean();

    // Capture status via http_response_code().
    $rawStatus = http_response_code();
    $status    = ($rawStatus !== false) ? (int) $rawStatus : $response->statusCode;

    // Capture headers via headers_list(); fall back to the Response object's
    // headers when running in CLI where header() calls are not tracked.
    $headers = [];
    foreach (headers_list() as $raw) {
        $colonPos = strpos($raw, ':');
        if ($colonPos !== false) {
            $name           = trim(substr($raw, 0, $colonPos));
            $headers[$name] = trim(substr($raw, $colonPos + 1));
        }
    }

    if (empty($headers)) {
        $headers = $response->headers;
    }

    $capturedSession = $_SESSION;

    // Reset globals that were overridden for this request.
    $_POST   = [];
    $_SERVER = $prevServer;

    return [
        'status'  => $status,
        'headers' => $headers,
        'body'    => $body,
        'session' => $capturedSession,
    ];
}
