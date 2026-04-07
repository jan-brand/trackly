<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Db\Db;
use App\Domain\Settings\Settings;
use App\Domain\Settings\SettingsRegistry;
use App\Http\Response;
use App\Security\Guard;

class AdminController
{
    public function settings(): Response
    {
        Guard::requireRole(['admin']);

        global $settings;
        $registry = new SettingsRegistry();
        /** @var Settings $settingsObj */
        $settingsObj = $settings instanceof Settings
            ? $settings
            : new Settings(Db::pdo(), $registry);

        $values = $settingsObj->all();

        $body = renderView('admin/settings', [
            'values'   => $values,
            'errors'   => [],
            'registry' => $registry,
        ]);

        return new Response(200, ['Content-Type' => 'text/html; charset=utf-8'], $body);
    }
}
