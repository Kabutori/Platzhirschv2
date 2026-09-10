<?php
use App\Modules\Support\Http\SupportController;
$r = app('router');
$r->middleware(['web', 'auth'])
    ->prefix('api/v1/support')
    ->group(function ($r) {
        $r->get('/', [SupportController::class, 'index']);
        $r->post('/', [SupportController::class, 'create']);
        $r->get('/{id}', [SupportController::class, 'show'])->whereNumber('id');
        $r->post('/{id}/messages', [SupportController::class, 'reply'])->whereNumber('id');
    });
$r->middleware(['web', 'auth'])
    ->prefix('api/v1/releases')
    ->group(function ($r) {
        $r->get('/', [\App\Modules\Support\Http\ReleaseController::class, 'index']);
        $r->post('/', [\App\Modules\Support\Http\ReleaseController::class, 'save'])->middleware(
            'throttle:10,1',
        );
        $r->patch('/{id}', [\App\Modules\Support\Http\ReleaseController::class, 'save'])
            ->whereNumber('id')
            ->middleware('throttle:10,1');
    });
