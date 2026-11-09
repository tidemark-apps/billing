<?php

// Staff account security (two-factor). Loaded inside the admin group via routes/admin.php.

use App\Http\Controllers\Admin\AccountSecurityController;
use Illuminate\Support\Facades\Route;

Route::prefix('account/security')->name('account.security.')->group(function () {
    Route::get('/', [AccountSecurityController::class, 'index'])->name('index');
    Route::post('start', [AccountSecurityController::class, 'start'])->name('start');
    Route::post('cancel', [AccountSecurityController::class, 'cancel'])->name('cancel');
    Route::post('confirm', [AccountSecurityController::class, 'confirm'])->name('confirm');
    Route::post('recovery-codes', [AccountSecurityController::class, 'recoveryCodes'])->name('recovery-codes');
    Route::delete('/', [AccountSecurityController::class, 'disable'])->name('disable');
    Route::delete('devices', [AccountSecurityController::class, 'revokeAllDevices'])->name('devices.destroy-all');
    Route::delete('devices/{device}', [AccountSecurityController::class, 'revokeDevice'])->whereNumber('device')->name('devices.destroy');
});
Route::post('users/{user}/two-factor/reset', [AccountSecurityController::class, 'reset'])->whereNumber('user')->name('users.two-factor.reset');
