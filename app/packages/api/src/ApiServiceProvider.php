<?php
namespace App\Modules\Api;
use Illuminate\Support\ServiceProvider;
use App\Contracts\Module\Module;
use App\Core\Module\ModuleRegistry;
class ApiServiceProvider extends ServiceProvider implements Module
{
    public function register(): void
    {
        $this->app->singleton(Catalog::class);
        if (!$this->app->bound(ModuleRegistry::class)) {
            $this->app->singleton(ModuleRegistry::class);
        }
        $this->app->make(ModuleRegistry::class)->register($this);
    }
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/migrations');
        $r = $this->app['router'];
        $r->middleware(['web', 'auth', Errors::class])
            ->prefix('api/v1/access')
            ->group(function ($r) {
                $r->get('/', [AdminController::class, 'index']);
                $r->post('/tokens', [AdminController::class, 'create'])->middleware('throttle:5,1');
                $r->post('/tokens/{id}/revoke', [AdminController::class, 'revoke'])
                    ->whereUuid('id')
                    ->middleware('throttle:10,1');
                $r->post('/confirmations/{id}/approve', [AdminController::class, 'approve'])
                    ->whereUuid('id')
                    ->middleware('throttle:10,1');
                $r->put('/modules/{module}', [AdminController::class, 'settings'])->middleware(
                    'throttle:10,1',
                );
            });
        $r->middleware([Errors::class, Authenticate::class])
            ->prefix('api/external/v1')
            ->group(function ($r) {
                $r->get('/catalog', [Gateway::class, 'catalog']);
                $r->get('/openapi.json', [Gateway::class, 'openapi']);
                $r->post('/confirmations', [Gateway::class, 'confirmation'])->middleware('throttle:10,1');
                $r->any('/{module}/{path?}', [Gateway::class, 'invoke'])->where('path', '.*');
            });
    }
    public function name(): string
    {
        return 'api';
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
    public function permissions(): array
    {
        return [];
    }
    public function platformMigrationsPath(): ?string
    {
        return __DIR__ . '/migrations';
    }
    public function tenantMigrationsPath(): ?string
    {
        return null;
    }
    public function tablePrefix(): string
    {
        return 'api_';
    }
}
