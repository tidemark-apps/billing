<?php

use App\Http\Controllers\Admin\ClientPaymentMethodController;
use Illuminate\Support\Facades\Route;

Route::delete('clients/{client}/payment-methods/{method}', [ClientPaymentMethodController::class, 'destroy'])->name('clients.payment-methods.destroy');
