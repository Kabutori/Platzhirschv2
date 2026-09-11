<?php
namespace App\Modules\Weather;
use Illuminate\Support\ServiceProvider;
use App\Contracts\Module\{Module, WeatherForecast};
use App\Core\Module\ModuleRegistry;
class WeatherServiceProvider extends ServiceProvider implements Module
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/config.php', 'weather');
        $this->app->bind(WeatherForecast::class, Forecast::class);
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
        return 'weather';
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
        return 'weather_';
    }
    public function tenantMigrationsPath(): ?string
    {
        return __DIR__ . '/migrations';
    }
    public function platformMigrationsPath(): ?string
    {
        return __DIR__ . '/platform';
    }
    public function permissions(): array
    {
        return [];
    }
}
