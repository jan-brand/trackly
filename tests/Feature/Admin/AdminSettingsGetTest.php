<?php

declare(strict_types=1);

namespace App\Tests\Feature\Admin;

use PHPUnit\Framework\TestCase;

/**
 * Feature tests for GET /admin/settings.
 */
class AdminSettingsGetTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
        $_POST    = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        $_POST    = [];
    }

    /**
     * An employee (non-admin) must receive HTTP 403 when accessing the settings screen.
     */
    public function testEmployeeGetSettingsReturns403(): void
    {
        $result = simulateRequest(
            'GET',
            '/admin/settings',
            [],
            [],
            [
                'user_id'      => 42,
                '__user_roles' => ['employee'],
            ],
        );

        $this->assertSame(403, $result['status']);
    }

    /**
     * A guest (not logged in) must also receive HTTP 403.
     */
    public function testGuestGetSettingsReturns403(): void
    {
        $result = simulateRequest(
            'GET',
            '/admin/settings',
            [],
            [],
            [], // no session → not logged in
        );

        $this->assertSame(403, $result['status']);
    }

    /**
     * A coordination role (non-admin) must also receive HTTP 403, because the
     * settings screen is restricted to admin only.
     */
    public function testCoordinationGetSettingsReturns403(): void
    {
        $result = simulateRequest(
            'GET',
            '/admin/settings',
            [],
            [],
            [
                'user_id'      => 99,
                '__user_roles' => ['coordination'],
            ],
        );

        $this->assertSame(403, $result['status']);
    }
}
