<?php
namespace App\Modules\IntegrationOdoo;
use Illuminate\Support\ServiceProvider;
use App\Contracts\Module\Module;
use App\Core\Module\ModuleRegistry;
class IntegrationOdooServiceProvider extends ServiceProvider implements Module
{
    public function register(): void
    {
        if (!$this->app->bound(ModuleRegistry::class)) {
            $this->app->singleton(ModuleRegistry::class);
        }
        $this->app->make(ModuleRegistry::class)->register($this);
    }
    public function boot(): void { app('router')->middleware(['web','auth','system'])->get('api/v1/admin/integrations/odoo',[StatusController::class,'index']); }
    public function name(): string
    {
        return 'integration-odoo';
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
        return 'integrationodoo_';
    }
    public function platformMigrationsPath(): ?string
    {
        return null;
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
