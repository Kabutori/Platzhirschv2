<?php
namespace App\Modules\Mcp;
use Illuminate\Support\ServiceProvider;
use App\Contracts\Module\Module;
use App\Core\Module\ModuleRegistry;
class McpServiceProvider extends ServiceProvider implements Module
{
    public function register(): void
    {
        if (!$this->app->bound(ModuleRegistry::class)) {
            $this->app->singleton(ModuleRegistry::class);
        }
        $this->app->make(ModuleRegistry::class)->register($this);
    }
    public function boot(): void {}
    public function name(): string
    {
        return 'mcp';
    }
    public function version(): string
    {
        return \App\Core\Module\PackageVersion::read(dirname(__DIR__) . '/composer.json');
    }
    public function dependencies(): array
    {
        return ['api' => '0.1.2'];
    }
    public function optionalDependencies(): array
    {
        return [];
    }
    public function permissions(): array
    {
        return [];
    }
    public function platformMigrationsPath(): ?string
    {
        return null;
    }
    public function tenantMigrationsPath(): ?string
    {
        return null;
    }
    public function tablePrefix(): string
    {
        return 'mcp_';
    }
}
