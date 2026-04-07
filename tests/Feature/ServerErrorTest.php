<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use PHPUnit\Framework\TestCase;

class ServerErrorTest extends TestCase
{
    public function testBoomRouteReturns500WithFehler(): void
    {
        $result = simulateRequest('GET', '/boom');

        $this->assertSame(500, $result['status']);
        $this->assertStringContainsString('Fehler', $result['body']);
    }
}
