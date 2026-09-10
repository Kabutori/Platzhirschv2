<?php
namespace Tests\Feature;
use Tests\TestCase;
use Symfony\Component\Process\Process;
class DevelopmentTest extends TestCase
{
    public function test_development_loader_uses_editable_module_sources(): void
    {
        $script =
            'require "vendor/autoload.php"; require "../development/autoload.php"; echo (new ReflectionClass(App\\Modules\\Reservation\\ReservationServiceProvider::class))->getFileName();';
        $process = new Process([PHP_BINARY, '-r', $script], base_path());
        $process->mustRun();
        $this->assertEquals(
            realpath(base_path('packages/reservation/src/ReservationServiceProvider.php')),
            realpath($process->getOutput()),
        );
    }
}
