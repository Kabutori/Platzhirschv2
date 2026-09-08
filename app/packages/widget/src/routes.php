<?php
use App\Modules\Widget\Http\WidgetController as C;
$r = app('router');
$r->middleware(['web', 'auth', 'tenant'])
    ->prefix('api/v1/restaurant/widget')
    ->group(function ($r) {
        $r->get('/', [C::class, 'list']);
        $r->post('/', [C::class, 'create']);
        $r->patch('/{id}', [C::class, 'update'])->whereNumber('id');
        $r->delete('/{id}', [C::class, 'revoke'])->whereNumber('id');
    });
$r->middleware(['api', 'throttle:widget'])
    ->prefix('api/widget')
    ->group(function ($r) {
        $r->get('/{token}', [C::class, 'config']);
        $r->post('/{token}', [C::class, 'book']);
        $r->options('/{token}', [C::class, 'options']);
        $r->get('/{token}/availability', [C::class, 'availability']);
        $r->options('/{token}/availability', [C::class, 'options']);
    });
