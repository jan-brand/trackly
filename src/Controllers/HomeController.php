<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\Response;

class HomeController
{
    public function index(): Response
    {
        $body = renderView('home');
        return new Response(200, ['Content-Type' => 'text/html; charset=utf-8'], $body);
    }
}
