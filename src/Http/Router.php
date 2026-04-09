<?php

declare(strict_types=1);

namespace App\Http;

class Router
{
    /** @var array<string, array<string, callable>> */
    private array $routes = [];

    /**
     * Parameterized route patterns.
     *
     * Each entry is an array{regex: string, params: list<string>, handler: callable}.
     *
     * @var array<string, list<array{regex: string, params: list<string>, handler: callable}>>
     */
    private array $patterns = [];

    public function get(string $path, callable $handler): void
    {
        $this->register('GET', $path, $handler);
    }

    public function post(string $path, callable $handler): void
    {
        $this->register('POST', $path, $handler);
    }

    private function register(string $method, string $path, callable $handler): void
    {
        if (str_contains($path, ':')) {
            $paramNames = [];
            $regex = preg_replace_callback(
                '/:([a-z_]+)/',
                static function (array $m) use (&$paramNames): string {
                    $paramNames[] = $m[1];
                    return '([^/]+)';
                },
                $path,
            );
            $this->patterns[$method][] = [
                'regex'   => '#^' . $regex . '$#',
                'params'  => $paramNames,
                'handler' => $handler,
            ];
        } else {
            $this->routes[$method][$path] = $handler;
        }
    }

    public function dispatch(string $method, string $path): Response
    {
        $method = strtoupper($method);

        // 1. Exact match
        $handler = $this->routes[$method][$path] ?? null;
        if ($handler !== null) {
            $_SERVER['ROUTE_PARAMS'] = [];
            return ($handler)();
        }

        // 2. Pattern match
        foreach ($this->patterns[$method] ?? [] as $pattern) {
            if (preg_match($pattern['regex'], $path, $matches)) {
                $params = [];
                foreach ($pattern['params'] as $i => $name) {
                    $params[$name] = $matches[$i + 1];
                }
                $_SERVER['ROUTE_PARAMS'] = $params;
                return ($pattern['handler'])();
            }
        }

        $body = renderView('errors/404');
        return new Response(404, ['Content-Type' => 'text/html; charset=utf-8'], $body);
    }
}
