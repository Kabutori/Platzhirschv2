<?php
namespace App\Providers;
use Illuminate\Support\ServiceProvider;
use App\Core\Module\ModuleRegistry;
class ModuleHostServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(
            \App\Contracts\Module\ProvisioningDispatcher::class,
            \App\Services\ProvisioningDispatcher::class,
        );
        $this->app->bind(
            \App\Contracts\Module\ServerTargets::class,
            fn($app) => $app->make(\App\Modules\Provisioning\PublicApi\ServerDirectory::class),
        );
        $this->app->bind(
            \App\Contracts\Module\TenantRuntime::class,
            \App\Services\ModuleTenantRuntime::class,
        );
        $this->app->bind(
            \App\Contracts\Module\AccountDirectory::class,
            \App\Services\ModuleDirectories::class,
        );
        $this->app->bind(
            \App\Contracts\Module\TenantDirectory::class,
            \App\Services\ModuleDirectories::class,
        );
        $this->app->bind(
            \App\Contracts\Module\ActivationDispatcher::class,
            \App\Services\ModuleActivationDispatcher::class,
        );
        if (!$this->app->bound(ModuleRegistry::class)) {
            $this->app->singleton(ModuleRegistry::class);
        }
        $this->app->bind(
            \App\Modules\Provisioning\PublicApi\InstalledDatabaseAccess::class,
            \App\Services\InstalledDatabaseDirectory::class,
        );
        $this->app->bind(\App\Contracts\Module\AuditSink::class, \App\Services\ModuleAuditSink::class);
    }
    public function boot(): void
    {
        $this->app->booted(
            fn() => $this->app
                ->make(ModuleRegistry::class)
                ->validate([
                    'identity',
                    'customer',
                    'provisioning',
                    'reservation',
                    'widget',
                    'billing',
                    'support',
                ]),
        );
    }
}
