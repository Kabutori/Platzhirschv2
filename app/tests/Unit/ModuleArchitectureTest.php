<?php
namespace Tests\Unit;
use PHPUnit\Framework\TestCase;
class ModuleArchitectureTest extends TestCase
{
    public function test_new_modules_do_not_import_facades_or_legacy_models(): void
    {
        foreach ($this->moduleFiles() as $file) {
            $source = file_get_contents($file);
            $this->assertStringNotContainsString('Illuminate\\Support\\Facades\\', $source, $file);
            $this->assertStringNotContainsString('App\\Models\\', $source, $file);
            $this->assertStringNotContainsString('App\\Services\\', $source, $file);
        }
    }
    public function test_core_does_not_reference_concrete_modules(): void
    {
        foreach ($this->phpFiles('Core') as $file) {
            $this->assertStringNotContainsString('App\\Modules\\', file_get_contents($file), $file);
        }
    }
    private function moduleFiles(): array
    {
        $paths = [];
        foreach (['identity', 'provisioning', 'billing', 'reporting'] as $module) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(__DIR__ . '/../../packages/' . $module . '/src'),
            );
            foreach ($it as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $paths[] = $file->getPathname();
                }
            }
        }
        return $paths;
    }
    private function phpFiles(string $directory): array
    {
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(__DIR__ . '/../../app/' . $directory),
        );
        $paths = [];
        foreach ($files as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $paths[] = $file->getPathname();
            }
        }
        return $paths;
    }
}
