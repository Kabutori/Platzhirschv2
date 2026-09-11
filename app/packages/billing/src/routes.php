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

$r->middleware(['web', 'auth', 'system'])
    ->prefix('api/v1/admin/billing')
    ->group(function ($r) {
        $c = \App\Modules\Billing\Http\InvoiceController::class;
        $r->get('/documents', [$c, 'index']);
        $r->put('/settings', [$c, 'settings']);
        $r->put('/profiles/{tenant}', [$c, 'profile'])->whereNumber('tenant');
        $r->post('/orders/{order}/invoice', [$c, 'draft'])->whereNumber('order');
        $r->post('/documents/{id}/issue', [$c, 'issue'])->whereNumber('id');
        $r->post('/documents/{id}/cancel', [$c, 'cancel'])->whereNumber('id');
        $r->delete('/documents/{id}', [$c, 'discard'])->whereNumber('id');
        $r->get('/documents/{id}/print', [$c, 'print'])->whereNumber('id');
    });
$r->middleware(['web', 'auth', 'tenant'])
    ->prefix('api/v1/restaurant/billing')
    ->group(function ($r) {
        $c = \App\Modules\Billing\Http\InvoiceController::class;
        $r->get('/', [$c, 'customer']);
        $r->put('/profile', [$c, 'profile']);
        $r->get('/documents/{id}/print', [$c, 'print'])->whereNumber('id');
    });
