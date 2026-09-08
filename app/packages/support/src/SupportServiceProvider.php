<?php
namespace App\Modules\Support;
use Illuminate\Support\ServiceProvider;
use App\Contracts\Module\Module;
use App\Core\Module\ModuleRegistry;
class SupportServiceProvider extends ServiceProvider implements Module
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
        $this->loadMigrationsFrom($this->platformMigrationsPath());
        $this->loadRoutesFrom(__DIR__ . '/routes.php');
    }
    public function name(): string
    {
        return 'support';
    }
    public function version(): string
    {
        return '0.1.0';
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
        return 'support_';
    }
    public function tenantMigrationsPath(): ?string
    {
        return null;
    }
    public function platformMigrationsPath(): ?string
    {
        return __DIR__ . '/migrations';
    }
    public function permissions(): array
    {
        return json_decode(
            '[{"code": "support", "scope": "both", "label": "Support", "permissions": [{"code": "support.access", "label": "Support-Tickets lesen und bearbeiten"}]}]',
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
    }
}
