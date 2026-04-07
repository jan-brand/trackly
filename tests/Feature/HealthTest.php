<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use PHPUnit\Framework\TestCase;

class HealthTest extends TestCase
{
    public function testHealthEndpointReturns200AndBodyOk(): void
    {
        $result = simulateRequest('GET', '/health');

        $this->assertSame(200, $result['status']);
        $this->assertSame('OK', $result['body']);
    }
}
