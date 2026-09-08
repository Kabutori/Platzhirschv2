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

use App\Modules\Identity\Http\{AuthController, RestaurantRoleController};
$r = app('router');
$r->middleware(['web'])
    ->prefix('api')
    ->group(function ($r) {
        $r->get('csrf', [AuthController::class, 'csrf']);
        $r->get('bootstrap-status', [AuthController::class, 'status']);
        $r->post('bootstrap/first-admin', [AuthController::class, 'bootstrap'])->middleware(
            'throttle:bootstrap',
        );
        $r->prefix('v1/admin/auth')->group(function () use ($r) {
            $r->post('login', [AuthController::class, 'login'])->middleware('throttle:login');
            $r->post('forgot-password', [AuthController::class, 'forgot'])->middleware('throttle:3,1');
            $r->post('reset-password', [AuthController::class, 'reset'])->middleware('throttle:5,1');
            $r->middleware('auth')->group(function () use ($r) {
                $r->get('me', [AuthController::class, 'me']);
                $r->post('logout', [AuthController::class, 'logout']);
                $r->post('mfa/begin', [AuthController::class, 'beginMfa'])->middleware('throttle:5,1');
                $r->post('mfa/confirm', [AuthController::class, 'confirmMfa'])->middleware('throttle:5,1');
            });
        });
    });
$r->middleware(['web', 'auth', 'tenant'])
    ->prefix('api/v1/restaurant/roles')
    ->group(function ($r) {
        $r->get('/', [RestaurantRoleController::class, 'index']);
        $r->post('/', [RestaurantRoleController::class, 'save']);
        $r->patch('/{id}', [RestaurantRoleController::class, 'save'])->whereNumber('id');
        $r->delete('/{id}', [RestaurantRoleController::class, 'delete'])->whereNumber('id');
    });

use App\Modules\Identity\Http\{UserController as U, TeamController as T};
$r->middleware(['web', 'auth', 'system'])
    ->prefix('api/v1/admin')
    ->group(function ($r) {
        $r->get('users', [U::class, 'users']);
        $r->post('users', [U::class, 'createUser']);
        $r->patch('users/{user}', [U::class, 'updateUser']);
        $r->post('users/{user}/invite', [U::class, 'invite'])->middleware('throttle:5,1');
        $r->get('roles', [U::class, 'roles']);
    });
$r->middleware(['web', 'auth', 'tenant'])
    ->prefix('api/v1/restaurant/team')
    ->group(function ($r) {
        $r->get('/', [T::class, 'team']);
        $r->post('/', [T::class, 'createTeam']);
        $r->patch('/{id}', [T::class, 'updateTeam'])->whereNumber('id');
    });

app('router')
    ->middleware(['web', 'auth', 'system'])
    ->prefix('api/v1/admin/role-rollout')
    ->group(function ($r) {
        $r->get('/', [\App\Modules\Identity\Http\RoleRolloutController::class, 'catalog']);
        $r->post('/preview', [
            \App\Modules\Identity\Http\RoleRolloutController::class,
            'preview',
        ])->middleware('throttle:10,1');
        $r->post('/apply', [\App\Modules\Identity\Http\RoleRolloutController::class, 'apply'])->middleware(
            'throttle:5,1',
        );
    });
