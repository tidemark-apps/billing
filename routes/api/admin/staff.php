<?php

use App\Http\Controllers\Api\Admin\StaffUserController;
use App\Http\Controllers\Api\Support\BindAfterAuth;
use Illuminate\Support\Facades\Route;

// ---- staff accounts (administrators only, on top of the abilities) ------------------------------------------------
Route::middleware(['ability:staff-users.read', BindAfterAuth::class])->group(function () {
    Route::get('/staff-users', [StaffUserController::class, 'index'])->name('staff-users.index');
    Route::get('/staff-users/{user}', [StaffUserController::class, 'show'])->name('staff-users.show');
});
Route::middleware(['ability:staff-users.write', BindAfterAuth::class])->group(function () {
    Route::post('/staff-users', [StaffUserController::class, 'store'])->name('staff-users.store');
    Route::patch('/staff-users/{user}', [StaffUserController::class, 'update'])->name('staff-users.update');
    Route::post('/staff-users/{user}/activate', [StaffUserController::class, 'activate'])->name('staff-users.activate');
    Route::post('/staff-users/{user}/deactivate', [StaffUserController::class, 'deactivate'])->name('staff-users.deactivate');
    Route::post('/staff-users/{user}/password-reset-link', [StaffUserController::class, 'sendResetLink'])->name('staff-users.password-reset-link');
    Route::post('/staff-users/{user}/two-factor/reset', [StaffUserController::class, 'resetTwoFactor'])->name('staff-users.two-factor.reset');
});
