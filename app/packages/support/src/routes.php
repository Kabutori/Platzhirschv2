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

app('router')
    ->middleware(['web', 'auth'])
    ->prefix('api/v1/support/exports')
    ->group(function ($r) {
        $c = \App\Modules\Support\Http\ExportController::class;
        $r->get('/', [$c, 'direct']);
        $r->post('/', [$c, 'start'])->middleware('throttle:5,1');
        $r->get('/{id}', [$c, 'status'])->whereUuid('id');
        $r->get('/{id}/download', [$c, 'download'])->whereUuid('id');
    });
