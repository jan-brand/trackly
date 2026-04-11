<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Db\Db;
use App\Domain\Holiday\HolidayRepository;
use App\Domain\Settings\Settings;
use App\Domain\Settings\SettingsRegistry;
use App\Domain\Settings\SettingsValidator;
use App\Domain\Settings\SettingsWriter;
use App\Http\Response;
use App\Security\Auth;
use App\Security\Csrf;
use App\Security\Guard;
use App\Support\Flash;

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
        $meta   = $settingsObj->meta();

        $syncedYears = (new HolidayRepository(Db::pdo()))->getSyncedYears();

        $body = renderView('admin/settings', [
            'values'      => $values,
            'errors'      => [],
            'registry'    => $registry,
            'meta'        => $meta,
            'syncedYears' => $syncedYears,
        ]);

        return new Response(200, ['Content-Type' => 'text/html; charset=utf-8'], $body);
    }

    public function saveSettings(): Response
    {
        Csrf::verifyOrFail();
        Guard::requireRole(['admin']);

        $reason      = trim($_POST['reason'] ?? '');
        $rawSettings = is_array($_POST['settings'] ?? null) ? $_POST['settings'] : [];

        $registry  = new SettingsRegistry();
        $errors    = [];

        // ----------------------------------------------------------------
        // Validate reason (min 3 chars)
        // ----------------------------------------------------------------
        if (strlen($reason) < 3) {
            $errors['reason'] = ['Begründung muss mindestens 3 Zeichen lang sein.'];
        }

        // ----------------------------------------------------------------
        // Validate settings payload
        // ----------------------------------------------------------------
        $validator = new SettingsValidator($registry);
        $result    = $validator->validateSettingsPayload($rawSettings);

        if (!empty($errors) || !$result->isValid()) {
            $errors = array_merge($errors, $result->errors);

            global $settings;
            $settingsObj = $settings instanceof Settings
                ? $settings
                : new Settings(Db::pdo(), $registry);

            $body = renderView('admin/settings', [
                'values'      => $settingsObj->all(),
                'errors'      => $errors,
                'registry'    => $registry,
                'meta'        => $settingsObj->meta(),
                'syncedYears' => (new HolidayRepository(Db::pdo()))->getSyncedYears(),
            ]);

            return new Response(422, ['Content-Type' => 'text/html; charset=utf-8'], $body);
        }

        // ----------------------------------------------------------------
        // Persist atomically (upsert + audit in 1 transaction)
        // ----------------------------------------------------------------
        $writer = new SettingsWriter(Db::pdo(), $registry);
        $writer->save((int) Auth::userId(), $reason, $result->values);

        Flash::addSuccess('Einstellungen gespeichert.');

        return new Response(303, ['Location' => '/admin/settings'], '');
    }
}
