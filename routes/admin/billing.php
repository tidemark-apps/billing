<?php

use App\Http\Controllers\Admin\InvoiceController;
use App\Http\Controllers\Admin\PaymentController;
use App\Http\Controllers\Admin\QuoteController;
use App\Http\Controllers\Admin\TaxRateController;
use Illuminate\Support\Facades\Route;

// Invoices
Route::get('invoices/tax-rate/{client}', [InvoiceController::class, 'taxRate'])->name('invoices.tax-rate');
Route::resource('invoices', InvoiceController::class);
Route::post('invoices/{invoice}/issue', [InvoiceController::class, 'issue'])->name('invoices.issue');
Route::post('invoices/{invoice}/resend', [InvoiceController::class, 'resend'])->name('invoices.resend');
Route::post('invoices/{invoice}/void', [InvoiceController::class, 'void'])->name('invoices.void');
Route::post('invoices/{invoice}/uncollectible', [InvoiceController::class, 'uncollectible'])->name('invoices.uncollectible');
Route::post('invoices/{invoice}/payments', [InvoiceController::class, 'recordPayment'])->name('invoices.payments.store');

// Quotes
Route::resource('quotes', QuoteController::class);
Route::post('quotes/{quote}/send', [QuoteController::class, 'send'])->name('quotes.send');
Route::post('quotes/{quote}/accept', [QuoteController::class, 'accept'])->name('quotes.accept');
Route::post('quotes/{quote}/decline', [QuoteController::class, 'decline'])->name('quotes.decline');
Route::post('quotes/{quote}/convert', [QuoteController::class, 'convert'])->name('quotes.convert');
Route::post('quotes/{quote}/duplicate', [QuoteController::class, 'duplicate'])->name('quotes.duplicate');

// Payments
Route::get('payments', [PaymentController::class, 'index'])->name('payments.index');
Route::post('payments/{payment}/refund', [PaymentController::class, 'refund'])->name('payments.refund');

// Tax rates
Route::resource('tax-rates', TaxRateController::class)->only(['index', 'store', 'update', 'destroy']);
