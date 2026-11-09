<?php

use Illuminate\Support\Facades\Route;
use Plugins\LicenseServer\Http\Controllers\ApiController;

// /api/v1/x/license-server/*  (public: software calls these with a licence key)
Route::get('public-key', [ApiController::class, 'publicKey'])->name('public-key');
Route::middleware('throttle:license-server')->group(function () {
    Route::post('verify', [ApiController::class, 'verify'])->name('verify');
    Route::post('activate', [ApiController::class, 'activate'])->name('activate');
    Route::post('deactivate', [ApiController::class, 'deactivate'])->name('deactivate');
});
