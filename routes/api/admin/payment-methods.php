<?php

use App\Http\Controllers\Api\Admin\AutopayController;
use App\Http\Controllers\Api\Admin\ClientPaymentMethodController;
use App\Http\Controllers\Api\Support\BindAfterAuth;
use Illuminate\Support\Facades\Route;

// ---- saved payment methods and automatic payment ------------------------------------------------------------------
// The autopay settings are a section of the settings API: GET/PUT /settings/autopay (see system.php).
Route::middleware(['ability:payment-methods.read', BindAfterAuth::class])->group(function () {
    Route::get('/clients/{client}/payment-methods', [ClientPaymentMethodController::class, 'index'])->name('clients.payment-methods.index');
});
Route::middleware(['ability:payment-methods.write', BindAfterAuth::class])->group(function () {
    Route::delete('/clients/{client}/payment-methods/{method}', [ClientPaymentMethodController::class, 'destroy'])->name('clients.payment-methods.destroy');
});
Route::get('/autopay/attempts', [AutopayController::class, 'attempts'])->middleware(['ability:autopay.read', BindAfterAuth::class])->name('autopay.attempts');
