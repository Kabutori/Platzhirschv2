<?php
use App\Modules\Customer\Http\{TenantController as C, ProfileController as P};
$r = app('router');
$r->middleware(['web', 'auth', 'system'])
    ->prefix('api/v1/admin')
    ->group(function ($r) {
        $r->get('tenants', [C::class, 'tenants']);
        $r->post('tenants', [C::class, 'createTenant']);
        $r->post('test-restaurant', [C::class, 'demoTenant']);
        $r->patch('tenants/{tenant}', [C::class, 'updateTenant']);
        $r->post('tenants/{tenant}/retry', [C::class, 'retryTenant']);
    });
$r->middleware(['web', 'auth', 'tenant'])
    ->prefix('api/v1/restaurant/profile')
    ->group(function ($r) {
        $r->get('/', [P::class, 'profile']);
        $r->patch('/', [P::class, 'updateProfile']);
    });

app('router')
    ->middleware(['web', 'auth', 'system'])
    ->prefix('api/v1/admin')
    ->group(function ($r) {
        $c = \App\Modules\Customer\Http\OrganizationController::class;
        $r->get('organizations', [$c, 'index']);
        $r->post('organizations', [$c, 'save']);
        $r->patch('organizations/{id}', [$c, 'save'])->whereNumber('id');
        $r->delete('organizations/{id}', [$c, 'delete'])->whereNumber('id');
        $r->patch('tenants/{id}/organization', [$c, 'assign'])->whereNumber('id');
    });
