<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\Response;
use App\Security\Guard;

class AdminController
{
    public function settings(): Response
    {
        Guard::requireRole(['admin', 'coordination']);

        $body = renderView('admin/settings');
        return new Response(200, ['Content-Type' => 'text/html; charset=utf-8'], $body);
    }
}
