<?php
namespace Tests\Feature;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class HttpEntryPointTest extends TestCase
{
    public function test_production_entry_point_serves_the_health_route(): void
    {
        $process = new Process([PHP_BINARY, 'tests/Fixtures/http-entry.php'], dirname(__DIR__, 2), [
            'APP_ENV' => 'testing',
            'APP_DEBUG' => 'false',
        ]);
        $process->mustRun();
        $this->assertStringContainsString('ENTRY_STATUS=200', $process->getOutput());
    }
}
