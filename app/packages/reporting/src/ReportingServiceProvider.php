<?php
namespace App\Modules\Reporting;
use Illuminate\Support\ServiceProvider;
use App\Contracts\Module\Module;
use App\Core\Module\ModuleRegistry;
class ReportingServiceProvider extends ServiceProvider implements Module
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
    }
    public function name(): string
    {
        return 'reporting';
    }
    public function version(): string
    {
        return '0.1.0';
    }
    public function dependencies(): array
    {
        return ['billing' => '0.1.0'];
    }
    public function optionalDependencies(): array
    {
        return [];
    }
    public function tablePrefix(): string
    {
        return 'reporting_';
    }
    public function tenantMigrationsPath(): ?string
    {
        return __DIR__ . '/migrations';
    }
    public function platformMigrationsPath(): ?string
    {
        return null;
    }
    public function permissions(): array
    {
        return [
            [
                'code' => 'reports',
                'scope' => 'restaurant',
                'label' => 'Auswertungen',
                'permissions' => [
                    ['code' => 'reporting.read', 'label' => 'Erweiterte Auswertungen ansehen'],
                    ['code' => 'reporting.manage', 'label' => 'Auswertungen speichern und löschen'],
                ],
            ],
        ];
    }
}
