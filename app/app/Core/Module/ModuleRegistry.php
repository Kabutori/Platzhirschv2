<?php
namespace App\Core\Module;
use App\Contracts\Module\Module;
use LogicException;
final class ModuleRegistry
{
    private array $modules = [];
    public function register(Module $module): void
    {
        $code = $module->name();
        if (!preg_match('/^[a-z][a-z0-9-]*$/D', $code) || isset($this->modules[$code])) {
            throw new LogicException('Invalid or duplicate module code.');
        }
        if (!preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/D', $module->version())) {
            throw new LogicException('Module must declare a fixed version.');
        }
        $this->modules[$code] = $module;
    }
    public function validate(array $required = []): void
    {
        foreach ($required as $code) {
            if (!isset($this->modules[$code])) {
                throw new LogicException('Required module missing.');
            }
        }
        $prefixes = $permissions = $visiting = $visited = [];
        foreach ($this->modules as $module) {
            $prefix = $module->tablePrefix();
            if (!preg_match('/^[a-z][a-z0-9]*_$/D', $prefix) || isset($prefixes[$prefix])) {
                throw new LogicException('Invalid or duplicate table prefix.');
            }
            $prefixes[$prefix] = true;
            foreach ($module->permissions() as $family) {
                foreach ($family['permissions'] as $permission) {
                    $code = $permission['code'];
                    if (isset($permissions[$code])) {
                        throw new LogicException('Duplicate permission code.');
                    }
                    $permissions[$code] = true;
                }
            }
        }
        $visit = function ($code) use (&$visit, &$visiting, &$visited): void {
            if (isset($visiting[$code])) {
                throw new LogicException('Module dependency cycle.');
            }
            if (isset($visited[$code])) {
                return;
            }
            $visiting[$code] = true;
            $module = $this->modules[$code];
            foreach ($module->dependencies() as $dependency => $version) {
                if (!isset($this->modules[$dependency])) {
                    throw new LogicException('Module dependency missing.');
                }
            }
            foreach (
                array_merge($module->optionalDependencies(), $module->dependencies())
                as $dependency => $version
            ) {
                if (!isset($this->modules[$dependency])) {
                    continue;
                }
                if ($this->modules[$dependency]->version() !== $version) {
                    throw new LogicException('Module version mismatch.');
                }
                $visit($dependency);
            }
            unset($visiting[$code]);
            $visited[$code] = true;
        };
        foreach (array_keys($this->modules) as $code) {
            $visit($code);
        }
    }
    public function catalog(): array
    {
        return array_map(
            fn(Module $m) => [
                'code' => $m->name(),
                'version' => $m->version(),
                'dependencies' => $m->dependencies(),
                'permissions' => $m->permissions(),
                'table_prefix' => $m->tablePrefix(),
                'installed' => true,
            ],
            array_values($this->modules),
        );
    }
    public function permissionFamilies(): array
    {
        $families = [];
        foreach ($this->modules as $module) {
            foreach ($module->permissions() as $family) {
                $families[] = ['module' => $module->name(), ...$family];
            }
        }
        return $families;
    }
}
