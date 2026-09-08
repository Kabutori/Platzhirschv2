<?php
use App\Modules\Reservation\Http\ReservationController as C;
$r = app('router');
$r->middleware(['web', 'auth', 'tenant'])
    ->prefix('api/v1/restaurant')
    ->group(function ($r) {
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
