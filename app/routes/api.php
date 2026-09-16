<?php
// Public API routes are supplied by installed module providers.

\Illuminate\Support\Facades\Route::get('module-registry/{path}', [\App\ModuleUpdates\Distribution::class, 'handle'])->where('path', '.*')->middleware('throttle:300,1');
