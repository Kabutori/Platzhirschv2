<?php
$r = app('router');
$r->middleware(['web', 'auth'])
    ->prefix('api/v1/releases')
    ->group(function ($r) {
        $r->get('/', [\App\Modules\Release\Http\ReleaseController::class, 'index']);
        $r->post('/', [\App\Modules\Release\Http\ReleaseController::class, 'save'])->middleware(
            'throttle:10,1',
        );
        $r->patch('/{id}', [\App\Modules\Release\Http\ReleaseController::class, 'save'])
            ->whereNumber('id')
            ->middleware('throttle:10,1');
    });
