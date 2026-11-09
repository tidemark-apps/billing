<?php

// Staff management: administrators only. Loaded inside the admin group via routes/admin.php.
// (Resetting a staff member's two-factor authentication is admin.users.two-factor.reset in account.php.)

use App\Http\Controllers\Admin\StaffController;
use App\Http\Middleware\EnsureAdmin;
use Illuminate\Support\Facades\Route;

Route::middleware(EnsureAdmin::class)->prefix('staff')->name('staff.')->group(function () {
    Route::get('/', [StaffController::class, 'index'])->name('index');
    Route::get('create', [StaffController::class, 'create'])->name('create');
    Route::post('/', [StaffController::class, 'store'])->name('store');
    Route::get('{user}/edit', [StaffController::class, 'edit'])->whereNumber('user')->name('edit');
    Route::put('{user}', [StaffController::class, 'update'])->whereNumber('user')->name('update');
    Route::post('{user}/activate', [StaffController::class, 'activate'])->whereNumber('user')->name('activate');
    Route::post('{user}/deactivate', [StaffController::class, 'deactivate'])->whereNumber('user')->name('deactivate');
    Route::post('{user}/password-reset-link', [StaffController::class, 'sendResetLink'])->whereNumber('user')->name('password-reset-link');
});
