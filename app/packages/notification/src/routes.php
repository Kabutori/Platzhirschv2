<?php
$r = app('router');
$r->middleware(['web', 'auth', 'tenant'])
    ->prefix('api/v1/restaurant')
    ->group(function ($r) {
        $r->post('/notifications/{id}/retry', [
            \App\Modules\Notification\Http\NotificationController::class,
            'retry',
        ])
            ->whereNumber('id')
            ->middleware('throttle:5,1,notification-retry');
        $r->get('/notifications', [\App\Modules\Notification\Http\NotificationController::class, 'index']);
        $r->patch('/notifications', [\App\Modules\Notification\Http\NotificationController::class, 'save']);
    });

$r->post('api/notification/status/{id}', [\App\Modules\Notification\Http\DeliveryController::class, 'status'])
    ->whereUuid('id')
    ->middleware('throttle:120,1,notification-status');
