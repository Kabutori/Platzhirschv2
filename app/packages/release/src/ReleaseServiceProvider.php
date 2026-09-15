<?php
namespace App\Modules\Release;
use Illuminate\Support\ServiceProvider;
use App\Contracts\Module\Module;
use App\Core\Module\ModuleRegistry;
class ReleaseServiceProvider extends ServiceProvider implements Module
{
    public function register(): void
    {
        if (!$this->app->bound(ModuleRegistry::class)) {
            $this->app->singleton(ModuleRegistry::class);
        }
        $this->app->make(ModuleRegistry::class)->register($this);
    }
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__ . '/routes.php');
        $this->loadMigrationsFrom($this->platformMigrationsPath());
    }
    public function name(): string
    {
        return 'release';
    }
    public function version(): string
    {
        return \App\Core\Module\PackageVersion::read(dirname(__DIR__) . '/composer.json');
    }
    public function dependencies(): array
    {
        return [];
    }
    public function optionalDependencies(): array
    {
        return [];
    }
    public function tablePrefix(): string
    {
        return 'release_';
    }
    public function platformMigrationsPath(): ?string
    {
        return __DIR__ . '/migrations';
    }
    public function tenantMigrationsPath(): ?string
    {
        return null;
    }
    public function permissions(): array
    {
        return [];
    }
}
