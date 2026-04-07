<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use PHPUnit\Framework\TestCase;

class NotFoundTest extends TestCase
{
    public function testUnknownRouteReturns404WithNichtGefunden(): void
    {
        $result = simulateRequest('GET', '/does-not-exist');

        $this->assertSame(404, $result['status']);
        $this->assertStringContainsString('Nicht gefunden', $result['body']);
    }
}
