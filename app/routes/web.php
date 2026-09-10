<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\PlatformController;

Route::get('/', [\App\Registration\RegistrationController::class, 'index']);
Route::get('/registrierung', [\App\Registration\RegistrationController::class, 'index']);
Route::post('/registrierung', [\App\Registration\RegistrationController::class, 'submit'])
    ->middleware('throttle:registration');
Route::get('/registrierung/bestaetigen/{token}', [\App\Registration\RegistrationController::class, 'confirm'])
    ->where('token', '[a-f0-9]{64}')->middleware('throttle:20,1');
Route::post('/registrierung/bestaetigen/{token}', [\App\Registration\RegistrationController::class, 'complete'])
    ->where('token', '[a-f0-9]{64}')->middleware('throttle:10,1');
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
            Route::get('mail-settings', [\App\MailSettings\Controller::class, 'index']);
            Route::put('mail-settings', [\App\MailSettings\Controller::class, 'save']);
            Route::post('mail-settings/test', [\App\MailSettings\Controller::class, 'test'])->middleware('throttle:3,1');
            Route::get('modules', fn() => app(\App\Core\Module\ModuleRegistry::class)->catalog());
            Route::get(
                'permissions/families',
                fn() => app(\App\Core\Module\ModuleRegistry::class)->permissionFamilies(),
            );
            Route::get('dashboard', [PlatformController::class, 'dashboard']);
            Route::post('tenant-moves', [
                \App\Http\Controllers\TenantOperationController::class,
                'moveBatch',
            ])->middleware('throttle:3,1');
            Route::get('operations', [\App\Http\Controllers\TenantOperationController::class, 'index']);
            Route::post('tenants/{tenant}/move', [
                \App\Http\Controllers\TenantOperationController::class,
                'move',
            ])->middleware('throttle:3,1');
            Route::get('audit-log', [PlatformController::class, 'audit']);
            Route::get('system-operations', [\App\Operations\Controller::class, 'index']);
            Route::post('system-operations', [\App\Operations\Controller::class, 'store'])->middleware('throttle:3,1,system-operations:');
            Route::get('health', [PlatformController::class, 'health']);
        });
});
