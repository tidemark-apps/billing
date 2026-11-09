<?php

use App\Http\Controllers\Api\Admin\InvoiceController;
use App\Http\Controllers\Api\Admin\PaymentController;
use App\Http\Controllers\Api\Admin\PdfController;
use App\Http\Controllers\Api\Admin\QuoteController;
use App\Http\Controllers\Api\Admin\TaxRateController;
use App\Http\Controllers\Api\Support\BindAfterAuth;
use Illuminate\Support\Facades\Route;

// ---- invoices -----------------------------------------------------------------------------------------------------
Route::middleware(['ability:invoices.read', BindAfterAuth::class])->group(function () {
    Route::get('/invoices', [InvoiceController::class, 'index'])->name('invoices.index');
    Route::get('/invoices/summary', [InvoiceController::class, 'summary'])->name('invoices.summary');
    Route::get('/invoices/{invoice}', [InvoiceController::class, 'show'])->name('invoices.show');
    Route::get('/invoices/{invoice}/pdf', [PdfController::class, 'invoice'])->name('invoices.pdf');
});
Route::middleware(['ability:invoices.write', BindAfterAuth::class])->group(function () {
    Route::post('/invoices', [InvoiceController::class, 'store'])->name('invoices.store');
    Route::patch('/invoices/{invoice}', [InvoiceController::class, 'update'])->name('invoices.update');
    Route::delete('/invoices/{invoice}', [InvoiceController::class, 'destroy'])->name('invoices.destroy');
    Route::post('/invoices/{invoice}/issue', [InvoiceController::class, 'issue'])->name('invoices.issue');
    Route::post('/invoices/{invoice}/resend', [InvoiceController::class, 'resend'])->name('invoices.resend');
    Route::post('/invoices/{invoice}/void', [InvoiceController::class, 'void'])->name('invoices.void');
    Route::post('/invoices/{invoice}/uncollectible', [InvoiceController::class, 'uncollectible'])->name('invoices.uncollectible');
    Route::post('/invoices/{invoice}/payments', [InvoiceController::class, 'recordPayment'])->name('invoices.payments.store');
});

// ---- payments -----------------------------------------------------------------------------------------------------
Route::middleware(['ability:payments.read', BindAfterAuth::class])->group(function () {
    Route::get('/payments', [PaymentController::class, 'index'])->name('payments.index');
    Route::get('/payments/summary', [PaymentController::class, 'summary'])->name('payments.summary');
    Route::get('/payments/{payment}', [PaymentController::class, 'show'])->name('payments.show');
});
Route::post('/payments/{payment}/refund', [PaymentController::class, 'refund'])->middleware(['ability:payments.write', BindAfterAuth::class])->name('payments.refund');

// ---- quotes -------------------------------------------------------------------------------------------------------
Route::middleware(['ability:quotes.read', BindAfterAuth::class])->group(function () {
    Route::get('/quotes', [QuoteController::class, 'index'])->name('quotes.index');
    Route::get('/quotes/{quote}', [QuoteController::class, 'show'])->name('quotes.show');
    Route::get('/quotes/{quote}/pdf', [PdfController::class, 'quote'])->name('quotes.pdf');
});
Route::middleware(['ability:quotes.write', BindAfterAuth::class])->group(function () {
    Route::post('/quotes', [QuoteController::class, 'store'])->name('quotes.store');
    Route::patch('/quotes/{quote}', [QuoteController::class, 'update'])->name('quotes.update');
    Route::delete('/quotes/{quote}', [QuoteController::class, 'destroy'])->name('quotes.destroy');
    Route::post('/quotes/{quote}/send', [QuoteController::class, 'send'])->name('quotes.send');
    Route::post('/quotes/{quote}/convert', [QuoteController::class, 'convert'])->name('quotes.convert');
    Route::post('/quotes/{quote}/accept', [QuoteController::class, 'accept'])->name('quotes.accept');
    Route::post('/quotes/{quote}/decline', [QuoteController::class, 'decline'])->name('quotes.decline');
    Route::post('/quotes/{quote}/duplicate', [QuoteController::class, 'duplicate'])->name('quotes.duplicate');
});

// ---- tax rates ----------------------------------------------------------------------------------------------------
Route::middleware(['ability:tax-rates.read', BindAfterAuth::class])->group(function () {
    Route::get('/tax-rates', [TaxRateController::class, 'index'])->name('tax-rates.index');
    Route::get('/tax-rates/{taxRate}', [TaxRateController::class, 'show'])->name('tax-rates.show');
});
Route::middleware(['ability:tax-rates.write', BindAfterAuth::class])->group(function () {
    Route::post('/tax-rates', [TaxRateController::class, 'store'])->name('tax-rates.store');
    Route::patch('/tax-rates/{taxRate}', [TaxRateController::class, 'update'])->name('tax-rates.update');
    Route::delete('/tax-rates/{taxRate}', [TaxRateController::class, 'destroy'])->name('tax-rates.destroy');
});
