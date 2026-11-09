<?php

// Client-area account security (two-factor). Loaded inside the portal group via routes/portal.php.

use App\Http\Controllers\Portal\SecurityController;
use Illuminate\Support\Facades\Route;

Route::prefix('security')->name('security.')->group(function () {
    Route::get('/', [SecurityController::class, 'index'])->name('index');
    Route::post('start', [SecurityController::class, 'start'])->name('start');
    Route::post('cancel', [SecurityController::class, 'cancel'])->name('cancel');
    Route::post('confirm', [SecurityController::class, 'confirm'])->name('confirm');
    Route::post('recovery-codes', [SecurityController::class, 'recoveryCodes'])->name('recovery-codes');
    Route::delete('/', [SecurityController::class, 'disable'])->name('disable');
    Route::delete('devices', [SecurityController::class, 'revokeAllDevices'])->name('devices.destroy-all');
    Route::delete('devices/{device}', [SecurityController::class, 'revokeDevice'])->whereNumber('device')->name('devices.destroy');
});
