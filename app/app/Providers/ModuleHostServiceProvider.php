<?php
namespace App\Providers;
use Illuminate\Support\ServiceProvider;
use App\Core\Module\ModuleRegistry;
class ModuleHostServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        if (!$this->app->bound(ModuleRegistry::class)) $this->app->singleton(ModuleRegistry::class);
        $this->app->bind(
            \App\Modules\Provisioning\PublicApi\InstalledDatabaseAccess::class,
            \App\Services\InstalledDatabaseDirectory::class,
        );
        $this->app->bind(\App\Contracts\Module\AuditSink::class, \App\Services\ModuleAuditSink::class);
    }
    public function boot(): void
    {
        $this->app->booted(fn() => $this->app->make(ModuleRegistry::class)->validate(['provisioning']));
    }
}
