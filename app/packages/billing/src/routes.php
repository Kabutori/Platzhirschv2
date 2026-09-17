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
        $r->get('/export', [$c, 'export']);
        $r->get('/documents/{id}/pdf', [$c, 'pdf'])->whereNumber('id');
        $r->get('/documents/{id}/print', [$c, 'print'])->whereNumber('id');
    });
$r->middleware(['web', 'auth', 'tenant'])
    ->prefix('api/v1/restaurant/billing')
    ->group(function ($r) {
        $c = \App\Modules\Billing\Http\InvoiceController::class;
        $r->get('/', [$c, 'customer']);
        $r->put('/profile', [$c, 'profile']);
        $r->get('/export', [$c, 'export']);
        $r->get('/documents/{id}/pdf', [$c, 'pdf'])->whereNumber('id');
        $r->get('/documents/{id}/print', [$c, 'print'])->whereNumber('id');
    });

$r->middleware(['web', 'auth', 'system'])
    ->prefix('api/v1/admin/billing/automation')
    ->group(function ($r) {
        $c = \App\Modules\Billing\Http\AutomationController::class;
        $r->get('/', [$c, 'index']);
        $r->put('/', [$c, 'settings'])->middleware('throttle:5,1');
        $r->post('/run', [$c, 'run'])->middleware('throttle:2,1');
        $r->post('/documents/{id}/send', [$c, 'send'])->whereNumber('id');
        $r->post('/deliveries/{id}/retry', [$c, 'retry'])
            ->whereNumber('id')
            ->middleware('throttle:5,1');
    });
$r->middleware(['web', 'auth', 'tenant'])
    ->prefix('api/v1/restaurant/billing/automation')
    ->group(function ($r) {
        $c = \App\Modules\Billing\Http\AutomationController::class;
        $r->get('/', [$c, 'index']);
        $r->post('/checkout', [$c, 'checkout'])->middleware('throttle:5,1');
        $r->post('/subscriptions/{id}/cancel', [$c, 'cancel'])
            ->whereNumber('id')
            ->middleware('throttle:5,1');
        $r->post('/subscriptions/{id}/portal', [$c, 'portal'])
            ->whereNumber('id')
            ->middleware('throttle:5,1');
    });
// Provider-authenticated raw-body endpoint, deliberately outside session/CSRF middleware.
$r->post('api/v1/billing/stripe/webhook', [
    \App\Modules\Billing\Http\AutomationController::class,
    'webhook',
])->middleware('throttle:120,1');
