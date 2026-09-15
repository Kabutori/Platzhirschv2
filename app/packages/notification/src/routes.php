<?php
$r = app('router');
$r->middleware(['web', 'auth', 'tenant'])
    ->prefix('api/v1/restaurant')
    ->group(function ($r) {
        $r->get('/notifications', [\App\Modules\Notification\Http\NotificationController::class, 'index']);
        $r->patch('/notifications', [\App\Modules\Notification\Http\NotificationController::class, 'save']);
    });
