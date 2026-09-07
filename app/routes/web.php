<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\{
    AuthController,
    PlatformController,
    RestaurantController,
    SupportController,
    WidgetController,
    RoleController,
};

Route::get('/', fn() => redirect('/administration/login'));
Route::get('/admin/{path?}', function () {
    $file = public_path('admin/index.html');
    abort_unless(is_file($file), 503, 'Frontend noch nicht gebaut.');
    return response()->file($file);
})->where('path', '.*');
Route::get('/{portal}/{path?}', function () {
    $file = public_path('admin/index.html');
    abort_unless(is_file($file), 503, 'Frontend noch nicht gebaut.');
    return response()->file($file);
})
    ->where('portal', 'administration|restaurant')
    ->where('path', '.*');
// Same-origin browser API deliberately uses Laravel's web middleware: real sessions + CSRF.
Route::prefix('api')->group(function () {
    Route::get('csrf', [AuthController::class, 'csrf']);
    Route::get('bootstrap-status', [AuthController::class, 'status']);
    Route::post('bootstrap/first-admin', [AuthController::class, 'bootstrap'])->middleware(
        'throttle:bootstrap',
    );
    Route::prefix('v1/admin/auth')->group(function () {
        Route::post('login', [AuthController::class, 'login'])->middleware('throttle:login');
        Route::post('forgot-password', [AuthController::class, 'forgot'])->middleware('throttle:3,1');
        Route::post('reset-password', [AuthController::class, 'reset'])->middleware('throttle:5,1');
        Route::middleware('auth')->group(function () {
            Route::get('me', [AuthController::class, 'me']);
            Route::post('logout', [AuthController::class, 'logout']);
            Route::post('mfa/begin', [AuthController::class, 'beginMfa'])->middleware('throttle:5,1');
            Route::post('mfa/confirm', [AuthController::class, 'confirmMfa'])->middleware('throttle:5,1');
        });
    });
    Route::prefix('v1/admin')
        ->middleware(['auth', 'system'])
        ->group(function () {
            Route::get('modules', fn() => app(\App\Core\Module\ModuleRegistry::class)->catalog());
            Route::get(
                'permissions/families',
                fn() => app(\App\Core\Module\ModuleRegistry::class)->permissionFamilies(),
            );
            Route::get('dashboard', [PlatformController::class, 'dashboard']);
            Route::post('test-restaurant', [PlatformController::class, 'demoTenant']);
            Route::get('tenants', [PlatformController::class, 'tenants']);
            Route::post('tenants', [PlatformController::class, 'createTenant']);
            Route::patch('tenants/{tenant}', [PlatformController::class, 'updateTenant']);
            Route::post('tenants/{tenant}/retry', [PlatformController::class, 'retryTenant']);
            Route::get('users', [PlatformController::class, 'users']);
            Route::post('users', [PlatformController::class, 'createUser']);
            Route::patch('users/{user}', [PlatformController::class, 'updateUser']);
            Route::post('users/{user}/invite', [PlatformController::class, 'invite'])->middleware(
                'throttle:5,1',
            );
            Route::get('roles', [PlatformController::class, 'roles']);
            Route::get('audit-log', [PlatformController::class, 'audit']);
            Route::get('health', [PlatformController::class, 'health']);
        });
    Route::prefix('v1/restaurant')
        ->middleware(['auth', 'tenant'])
        ->group(function () {
            Route::get('profile', [RestaurantController::class, 'profile']);
            Route::patch('profile', [RestaurantController::class, 'updateProfile']);
            Route::get('reservations', [RestaurantController::class, 'reservations']);
            Route::get('export', [RestaurantController::class, 'export']);
            Route::post('reservations', [RestaurantController::class, 'saveReservation']);
            Route::patch('reservations/{id}', [RestaurantController::class, 'saveReservation']);
            Route::post('reservations/{id}/cancel', [RestaurantController::class, 'cancel']);
            Route::get('roles', [RoleController::class, 'index']);
            Route::post('roles', [RoleController::class, 'save']);
            Route::patch('roles/{id}', [RoleController::class, 'save']);
            Route::delete('roles/{id}', [RoleController::class, 'delete']);
            Route::patch('team/{id}', [RestaurantController::class, 'updateTeam']);
            Route::get('team', [RestaurantController::class, 'team']);
            Route::post('team', [RestaurantController::class, 'createTeam']);
            Route::get('widget', [WidgetController::class, 'list']);
            Route::post('widget', [WidgetController::class, 'create']);
            Route::delete('widget/{id}', [WidgetController::class, 'revoke']);
            Route::get('{resource}', [RestaurantController::class, 'index']);
            Route::post('{resource}', [RestaurantController::class, 'save']);
            Route::patch('{resource}/{id}', [RestaurantController::class, 'save']);
            Route::delete('{resource}/{id}', [RestaurantController::class, 'delete']);
        });
    Route::prefix('v1/support')
        ->middleware('auth')
        ->group(function () {
            Route::get('/', [SupportController::class, 'index']);
            Route::post('/', [SupportController::class, 'create']);
            Route::get('{id}', [SupportController::class, 'show']);
            Route::post('{id}/messages', [SupportController::class, 'reply']);
        });
});
