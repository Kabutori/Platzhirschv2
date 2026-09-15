<?php
namespace Tests\Unit;
use App\Core\Module\PackageVersion;
use PHPUnit\Framework\TestCase;
class PackageVersionTest extends TestCase
{
    public function test_module_version_changes_without_changing_the_core(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'module-version-');
        try {
            file_put_contents($file, json_encode(['version' => '0.2.3']));
            $this->assertSame('0.2.3', PackageVersion::read($file));
            file_put_contents($file, json_encode(['version' => '0.2.4']));
            $this->assertSame('0.2.4', PackageVersion::read($file));
            file_put_contents($file, json_encode(['version' => 'dev-main']));
            $this->expectException(\LogicException::class);
            PackageVersion::read($file);
        } finally {
            unlink($file);
        }
    }
}
