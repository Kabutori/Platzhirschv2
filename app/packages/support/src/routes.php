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
