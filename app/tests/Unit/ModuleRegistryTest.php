<?php
namespace Tests\Unit;
use App\Contracts\Module\Module;
use App\Core\Module\ModuleRegistry;
use PHPUnit\Framework\TestCase;
class ModuleRegistryTest extends TestCase
{
    private function module(
        string $name,
        array $dependencies = [],
        string $prefix = '',
        array $permissions = [],
    ): Module {
        $m = $this->createMock(Module::class);
        $m->method('name')->willReturn($name);
        $m->method('version')->willReturn('1.0.0');
        $m->method('dependencies')->willReturn($dependencies);
        $m->method('optionalDependencies')->willReturn([]);
        $m->method('tablePrefix')->willReturn($prefix ?: $name . '_');
        $m->method('permissions')->willReturn($permissions);
        return $m;
    }
    public function test_missing_dependency_is_rejected(): void
    {
        $r = new ModuleRegistry();
        $r->register($this->module('a', ['b' => '1.0.0']));
        $this->expectException(\LogicException::class);
        $r->validate();
    }
    public function test_duplicate_module_is_rejected(): void
    {
        $r = new ModuleRegistry();
        $r->register($this->module('a'));
        $this->expectException(\LogicException::class);
        $r->register($this->module('a'));
    }
    public function test_cycle_is_rejected(): void
    {
        $r = new ModuleRegistry();
        $r->register($this->module('a', ['b' => '1.0.0']));
        $r->register($this->module('b', ['a' => '1.0.0']));
        $this->expectException(\LogicException::class);
        $r->validate();
    }
    public function test_table_prefix_collision_is_rejected(): void
    {
        $r = new ModuleRegistry();
        $r->register($this->module('a', [], 'shared_'));
        $r->register($this->module('b', [], 'shared_'));
        $this->expectException(\LogicException::class);
        $r->validate();
    }
    public function test_duplicate_permission_is_rejected(): void
    {
        $permissions = [['code' => 'group', 'permissions' => [['code' => 'a.read', 'label' => 'Read']]]];
        $r = new ModuleRegistry();
        $r->register($this->module('a', [], '', $permissions));
        $r->register($this->module('b', [], '', $permissions));
        $this->expectException(\LogicException::class);
        $r->validate();
    }
    public function test_version_mismatch_is_rejected(): void
    {
        $r = new ModuleRegistry();
        $r->register($this->module('a', ['b' => '2.0.0']));
        $r->register($this->module('b'));
        $this->expectException(\LogicException::class);
        $r->validate();
    }
    public function test_registry_exposes_only_installed_modules(): void
    {
        $r = new ModuleRegistry();
        $r->register($this->module('a'));
        $r->validate(['a']);
        $this->assertSame('a', $r->catalog()[0]['code']);
    }
}
