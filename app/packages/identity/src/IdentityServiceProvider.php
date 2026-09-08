<?php
namespace App\Modules\Identity;
use Illuminate\Support\ServiceProvider;
use App\Contracts\Module\Module;
use App\Core\Module\ModuleRegistry;
class IdentityServiceProvider extends ServiceProvider implements Module
{
    public function register(): void
    {
        $this->app->bind(
            \App\Contracts\Module\AccountProvisioner::class,
            Application\AccountProvisioner::class,
        );
        if (!$this->app->bound(ModuleRegistry::class)) {
            $this->app->singleton(ModuleRegistry::class);
        }
        $this->app->make(ModuleRegistry::class)->register($this);
        $this->app->bind(PublicApi\RoleDirectory::class, Application\RoleDirectory::class);
    }
    public function boot(): void
    {
        $this->loadMigrationsFrom($this->platformMigrationsPath());
        $this->loadRoutesFrom(__DIR__ . '/routes.php');
    }
    public function name(): string
    {
        return 'identity';
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
        return 'identity_';
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
        return [
            [
                'code' => 'platform',
                'label' => 'Plattformverwaltung',
                'permissions' => [
                    ['code' => 'platform.dashboard.read', 'label' => 'Plattformübersicht ansehen'],
                    ['code' => 'platform.tenants.read', 'label' => 'Mandanten ansehen'],
                    ['code' => 'platform.modules.read', 'label' => 'Modulkatalog ansehen'],
                    ['code' => 'platform.users.read', 'label' => 'Benutzer ansehen'],
                    ['code' => 'platform.roles.manage', 'label' => 'Rollen verwalten und aktivieren'],
                ],
            ],
            [
                'code' => 'system',
                'label' => 'System und Support',
                'permissions' => [
                    ['code' => 'platform.audit.read', 'label' => 'Audit Log ansehen'],
                    ['code' => 'platform.health.read', 'label' => 'Systemstatus ansehen'],
                    ['code' => 'support.access', 'label' => 'Support-Tickets bearbeiten'],
                ],
            ],
        ];
    }
}
