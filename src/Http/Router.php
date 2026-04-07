<?php

declare(strict_types=1);

namespace App\Http;

class Router
{
    /** @var array<string, array<string, callable>> */
    private array $routes = [];

    public function get(string $path, callable $handler): void
    {
        $this->routes['GET'][$path] = $handler;
    }

    public function post(string $path, callable $handler): void
    {
        $this->routes['POST'][$path] = $handler;
    }

    public function dispatch(string $method, string $path): Response
    {
        $method = strtoupper($method);
        $handler = $this->routes[$method][$path] ?? null;

        if ($handler === null) {
            $body = renderView('errors/404');
            return new Response(404, ['Content-Type' => 'text/html; charset=utf-8'], $body);
        }

        return ($handler)();
    }
}
