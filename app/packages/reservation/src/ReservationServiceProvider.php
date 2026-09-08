<?php
namespace App\Modules\Reservation;
use Illuminate\Support\ServiceProvider;
use App\Contracts\Module\Module;
use App\Core\Module\ModuleRegistry;
class ReservationServiceProvider extends ServiceProvider implements Module
{
    public function register(): void
    {
        $this->app->bind(
            \App\Modules\Reservation\PublicApi\ReservationGateway::class,
            \App\Modules\Reservation\Application\ReservationService::class,
        );
        $this->app->bind(
            \App\Contracts\Module\ReservationReports::class,
            \App\Modules\Reservation\Application\ReservationReports::class,
        );
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
        return 'reservation';
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
        return 'reservation_';
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
        return json_decode(
            '[{"code": "reservations", "scope": "restaurant", "label": "Reservierungen und Restaurantbetrieb", "permissions": [{"code": "reservation.read", "label": "Reservierungen und Belegung ansehen"}, {"code": "reservation.write", "label": "Reservierungen anlegen und bearbeiten"}, {"code": "reservation.cancel", "label": "Reservierungen stornieren"}, {"code": "reservation.export", "label": "Gästedaten exportieren"}, {"code": "waitlist.read", "label": "Warteliste ansehen"}, {"code": "waitlist.write", "label": "Warteliste verwalten"}, {"code": "restaurant.configure", "label": "Räume, Tische und Öffnungszeiten verwalten"}]}]',
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
    }
}
