<?php
use App\Modules\Reservation\Http\ReservationController as C;
$r = app('router');
$r->middleware(['web', 'auth', 'tenant'])
    ->prefix('api/v1/restaurant')
    ->group(function ($r) {
        $a = \App\Modules\Reservation\Http\AvailabilityController::class;
        $r->get('/{kind}', [$a, 'index'])->where('kind', 'room-closures|table-combinations');
        $r->post('/{kind}', [$a, 'save'])->where('kind', 'room-closures|table-combinations');
        $r->patch('/{kind}/{id}', [$a, 'save'])
            ->where('kind', 'room-closures|table-combinations')
            ->whereNumber('id');
        $r->delete('/{kind}/{id}', [$a, 'delete'])
            ->where('kind', 'room-closures|table-combinations')
            ->whereNumber('id');

        $r->get('/notifications', [\App\Modules\Reservation\Http\NotificationController::class, 'index']);
        $r->patch('/notifications', [\App\Modules\Reservation\Http\NotificationController::class, 'save']);
        $r->get('/waitlist', [\App\Modules\Reservation\Http\WaitlistController::class, 'index']);
        $r->post('/waitlist', [\App\Modules\Reservation\Http\WaitlistController::class, 'save']);
        $r->patch('/waitlist/{id}', [
            \App\Modules\Reservation\Http\WaitlistController::class,
            'save',
        ])->whereNumber('id');
        $r->post('/waitlist/{id}/book', [
            \App\Modules\Reservation\Http\WaitlistController::class,
            'book',
        ])->whereNumber('id');
        $r->get('/reservations', [C::class, 'reservations']);
        $r->get('/export', [C::class, 'export']);
        $r->post('/reservations', [C::class, 'saveReservation']);
        $r->patch('/reservations/{id}', [C::class, 'saveReservation'])->whereNumber('id');
        $r->post('/reservations/{id}/cancel', [C::class, 'cancel'])->whereNumber('id');
        $r->get('/{resource}', [C::class, 'index'])->where('resource', 'rooms|tables|hours|special-days');
        $r->post('/{resource}', [C::class, 'save'])->where('resource', 'rooms|tables|hours|special-days');
        $r->patch('/{resource}/{id}', [C::class, 'save'])
            ->where('resource', 'rooms|tables|hours|special-days')
            ->whereNumber('id');
        $r->delete('/{resource}/{id}', [C::class, 'delete'])
            ->where('resource', 'rooms|tables|hours|special-days')
            ->whereNumber('id');
    });
