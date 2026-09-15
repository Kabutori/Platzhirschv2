<?php
namespace App\Modules\Notification;
use Illuminate\Support\ServiceProvider;
use App\Contracts\Module\Module;
use App\Core\Module\ModuleRegistry;
class NotificationServiceProvider extends ServiceProvider implements Module
{
    public function register(): void
    {
        if (!$this->app->bound(ModuleRegistry::class)) {
            $this->app->singleton(ModuleRegistry::class);
        }
        $this->app->make(ModuleRegistry::class)->register($this);
        $this->app->bind(
            \App\Contracts\Module\ReservationNotifier::class,
            Application\ReservationNotifications::class,
        );
    }
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__ . '/routes.php');
    }
    public function name(): string
    {
        return 'notification';
    }
    public function version(): string
    {
        return \App\Core\Module\PackageVersion::read(dirname(__DIR__) . '/composer.json');
    }
    public function dependencies(): array
    {
        return ['reservation' => '0.1.0'];
    }
    public function optionalDependencies(): array
    {
        return [];
    }
    public function tablePrefix(): string
    {
        return 'notification_';
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
