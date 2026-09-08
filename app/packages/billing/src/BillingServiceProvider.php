<?php
namespace App\Modules\Billing;
use Illuminate\Support\ServiceProvider;
use App\Contracts\Module\Module;
use App\Core\Module\ModuleRegistry;
class BillingServiceProvider extends ServiceProvider implements Module
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
        return 'billing';
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
        return 'billing_';
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
        return [];
    }
}
