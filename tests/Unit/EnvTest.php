<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Support\Env;
use PHPUnit\Framework\TestCase;

class EnvTest extends TestCase
{
    private string $tmpFile;

    protected function setUp(): void
    {
        $this->tmpFile = tempnam(sys_get_temp_dir(), 'trackly_env_');
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tmpFile)) {
            unlink($this->tmpFile);
        }
    }

    public function testParsesKeyValuePairs(): void
    {
        file_put_contents($this->tmpFile, "APP_KEY=secret123\n");

        Env::load($this->tmpFile);

        $this->assertSame('secret123', $_ENV['APP_KEY']);
    }

    public function testIgnoresBlankLines(): void
    {
        file_put_contents($this->tmpFile, "\n\nDB_HOST=localhost\n\n");

        Env::load($this->tmpFile);

        $this->assertSame('localhost', $_ENV['DB_HOST']);
    }

    public function testIgnoresHashComments(): void
    {
        file_put_contents($this->tmpFile, "# This is a comment\nDB_NAME=trackly\n");

        Env::load($this->tmpFile);

        $this->assertArrayNotHasKey('# This is a comment', $_ENV);
        $this->assertSame('trackly', $_ENV['DB_NAME']);
    }

    public function testIgnoresLinesThatAreOnlyComment(): void
    {
        file_put_contents($this->tmpFile, "#COMMENTED_OUT=value\n");

        Env::load($this->tmpFile);

        $this->assertArrayNotHasKey('COMMENTED_OUT', $_ENV);
        $this->assertArrayNotHasKey('#COMMENTED_OUT', $_ENV);
    }

    public function testStripsDoubleQuotesFromValue(): void
    {
        file_put_contents($this->tmpFile, "APP_NAME=\"Trackly App\"\n");

        Env::load($this->tmpFile);

        $this->assertSame('Trackly App', $_ENV['APP_NAME']);
    }

    public function testValueWithEqualsSign(): void
    {
        file_put_contents($this->tmpFile, "BASE_URL=http://localhost/path=test\n");

        Env::load($this->tmpFile);

        $this->assertSame('http://localhost/path=test', $_ENV['BASE_URL']);
    }

    public function testNonExistentFileIsNoop(): void
    {
        $before = $_ENV;

        Env::load('/tmp/trackly_does_not_exist_at_all.env');

        $this->assertSame($before, $_ENV);
    }
}
