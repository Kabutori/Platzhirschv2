<?php
use App\Modules\Weather\WeatherController;
app('router')
    ->middleware(['web', 'auth', 'tenant'])
    ->prefix('api/v1/restaurant/weather')
    ->group(function ($r) {
        $r->get('/settings', [WeatherController::class, 'settings']);
        $r->put('/settings', [WeatherController::class, 'save']);
    });
