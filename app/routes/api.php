<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\WidgetController;
Route::middleware('throttle:widget')->group(function () {
    Route::get('widget/{token}', [WidgetController::class, 'config']);
    Route::options('widget/{token}', [WidgetController::class, 'options']);
    Route::post('widget/{token}', [WidgetController::class, 'book']);
    Route::get('widget/{token}/availability', [WidgetController::class, 'availability']);
    Route::options('widget/{token}/availability', [WidgetController::class, 'options']);
});
