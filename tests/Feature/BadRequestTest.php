<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use PHPUnit\Framework\TestCase;

class BadRequestTest extends TestCase
{
    public function testBadRequestRouteReturns400(): void
    {
        $result = simulateRequest('GET', '/demo/bad-request');

        $this->assertSame(400, $result['status']);
        $this->assertStringContainsString('Ungültige Anfrage', $result['body']);
    }
}
