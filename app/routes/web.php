<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\{PlatformController, RestaurantController};

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
            Route::get('operations', [\App\Http\Controllers\TenantOperationController::class, 'index']);
            Route::post('tenants/{tenant}/move', [
                \App\Http\Controllers\TenantOperationController::class,
                'move',
            ])->middleware('throttle:3,1');
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
            Route::patch('team/{id}', [RestaurantController::class, 'updateTeam']);
            Route::get('team', [RestaurantController::class, 'team']);
            Route::post('team', [RestaurantController::class, 'createTeam']);
        });
});
