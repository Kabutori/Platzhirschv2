<?php
use App\Modules\Identity\Http\RoleController;
app('router')
    ->middleware(['web', 'auth', 'system'])
    ->prefix('api/v1/admin/platform-roles')
    ->group(function ($router) {
        $router->get('/', [RoleController::class, 'index']);
        $router->post('/', [RoleController::class, 'save']);
        $router->delete('/{id}', [RoleController::class, 'delete'])->whereNumber('id');
        $router->patch('/{id}', [RoleController::class, 'save'])->whereNumber('id');
        $router->post('/{id}/check', [RoleController::class, 'check'])->whereNumber('id');
        $router->post('/{id}/activate', [RoleController::class, 'activate'])->whereNumber('id');
    });
