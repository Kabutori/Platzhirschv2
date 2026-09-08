<?php
use App\Modules\Reporting\Http\ReportController;
app('router')
    ->middleware(['web', 'auth', 'tenant'])
    ->prefix('api/v1/restaurant/reporting')
    ->group(function ($r) {
        $r->get('/', [ReportController::class, 'index']);
        $r->post('/saved', [ReportController::class, 'save']);
        $r->delete('/saved/{id}', [ReportController::class, 'delete'])->whereNumber('id');
    });
