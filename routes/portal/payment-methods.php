<?php

// Saved payment methods. Loaded inside the portal group (prefix portal, name portal.).

use App\Http\Controllers\Portal\PaymentMethodController;
use Illuminate\Support\Facades\Route;

Route::get('/payment-methods', [PaymentMethodController::class, 'index'])->name('payment-methods.index');
Route::post('/payment-methods/setup', [PaymentMethodController::class, 'setup'])->name('payment-methods.setup');
Route::get('/payment-methods/return/{gateway}', [PaymentMethodController::class, 'complete'])->name('payment-methods.return');
Route::post('/payment-methods/{method}/default', [PaymentMethodController::class, 'makeDefault'])->name('payment-methods.default');
Route::post('/payment-methods/{method}/autopay', [PaymentMethodController::class, 'autopay'])->name('payment-methods.autopay');
Route::delete('/payment-methods/{method}', [PaymentMethodController::class, 'destroy'])->name('payment-methods.destroy');
Route::post('/invoices/{invoice}/pay-saved', [PaymentMethodController::class, 'pay'])->whereNumber('invoice')->name('payment-methods.pay');
