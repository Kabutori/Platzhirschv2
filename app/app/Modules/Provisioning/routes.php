<?php
use App\Modules\Provisioning\Http\ServerController;
$router = app('router');
$router
    ->middleware(['web', 'auth', 'system'])
    ->prefix('api/v1/admin/database-servers')
    ->group(function ($router) {
        $router->get('/', [ServerController::class, 'index']);
        $router->post('/', [ServerController::class, 'save']);
        $router->patch('/{id}', [ServerController::class, 'save'])->whereNumber('id');
        $router
            ->post('/{id}/test', [ServerController::class, 'test'])
            ->whereNumber('id')
            ->middleware('throttle:6,1');
    });

$router
    ->middleware(['web', 'auth', 'system'])
    ->prefix('api/v1/admin/database-access')
    ->group(function ($router) {
        $router->get('/', [\App\Modules\Provisioning\Http\DatabaseAccessController::class, 'index']);
        $router
            ->post('/{connection}/reveal', [
                \App\Modules\Provisioning\Http\DatabaseAccessController::class,
                'reveal',
            ])
            ->middleware('throttle:5,1');
    });
