<?php
use App\Modules\Billing\Http\ModuleController;
$r = app('router');
$r->middleware(['web', 'auth', 'tenant'])
    ->prefix('api/v1/restaurant/modules')
    ->group(function ($r) {
        $r->get('/', [ModuleController::class, 'catalog']);
        $r->post('/orders', [ModuleController::class, 'order'])->middleware('throttle:10,1');
        $r->post('/{code}/activation', [ModuleController::class, 'activate'])->middleware('throttle:5,1');
    });
$r->middleware(['web', 'auth', 'system'])
    ->prefix('api/v1/admin/billing')
    ->group(function ($r) {
        $r->get('/', [ModuleController::class, 'administration']);
        $r->patch('/products/{code}', [ModuleController::class, 'price']);
        $r->post('/orders/{id}/confirm', [ModuleController::class, 'confirm'])->whereNumber('id');
    });
