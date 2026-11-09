<?php

use App\Http\Controllers\Api\Client\AuthController;
use App\Http\Controllers\Api\Client\CatalogController;
use App\Http\Controllers\Api\Client\InvoiceController;
use App\Http\Controllers\Api\Client\MeController;
use App\Http\Controllers\Api\Client\OrderController;
use App\Http\Controllers\Api\Client\PaymentController;
use App\Http\Controllers\Api\Client\PaymentMethodController;
use App\Http\Controllers\Api\Client\PdfController;
use App\Http\Controllers\Api\Client\QuoteController;
use App\Http\Controllers\Api\Client\ServiceController;
use App\Http\Controllers\Api\Client\UsageController;
use Illuminate\Support\Facades\Route;

// Public
Route::post('/auth/token', [AuthController::class, 'token'])->middleware('throttle:api-auth')->name('auth.token');
Route::post('/auth/register', [AuthController::class, 'register'])->middleware('throttle:api-auth')->name('auth.register');
Route::middleware('throttle:api')->group(function () {
    Route::get('/products', [CatalogController::class, 'products'])->name('products.index');
    Route::get('/products/{product}', [CatalogController::class, 'product'])->name('products.show');
});

// Any valid token
Route::delete('/auth/token', [AuthController::class, 'revoke'])->middleware(['auth.api', 'throttle:api'])->name('auth.revoke');

// Customer tokens, always scoped to the token owner's client
Route::middleware(['auth.api:customer', 'throttle:api'])->group(function () {
    Route::get('/me', [MeController::class, 'show'])->middleware('ability:profile.read')->name('me.show');
    Route::patch('/me', [MeController::class, 'update'])->middleware('ability:profile.write')->name('me.update');
    Route::get('/gateways', [CatalogController::class, 'gateways'])->middleware('ability:invoices.read')->name('gateways.index');

    Route::post('/orders', [OrderController::class, 'store'])->middleware('ability:orders.write')->name('orders.store');

    Route::middleware('ability:services.read')->group(function () {
        Route::get('/services', [ServiceController::class, 'index'])->name('services.index');
        Route::get('/services/{service}', [ServiceController::class, 'show'])->name('services.show');
    });
    Route::get('/services/{service}/usage', [UsageController::class, 'show'])->middleware('ability:usage.read')->name('services.usage');
    Route::middleware('ability:services.write')->group(function () {
        Route::post('/services/{service}/cancel', [ServiceController::class, 'cancel'])->name('services.cancel');
        Route::post('/services/{service}/actions/{action}', [ServiceController::class, 'action'])->name('services.action');
    });

    Route::middleware('ability:invoices.read')->group(function () {
        Route::get('/invoices', [InvoiceController::class, 'index'])->name('invoices.index');
        Route::get('/invoices/{invoice}', [InvoiceController::class, 'show'])->name('invoices.show');
        Route::get('/invoices/{invoice}/pdf', [PdfController::class, 'invoice'])->name('invoices.pdf');
        Route::get('/payments', [PaymentController::class, 'index'])->name('payments.index');
    });
    Route::post('/invoices/{invoice}/pay', [InvoiceController::class, 'pay'])->middleware('ability:invoices.pay')->name('invoices.pay');
    Route::post('/invoices/{invoice}/pay-saved', [PaymentMethodController::class, 'pay'])->middleware('ability:invoices.pay')->name('invoices.pay-saved');

    // Saved payment methods and automatic payment (tokens never leave the server)
    Route::middleware('ability:payment-methods.read')->group(function () {
        Route::get('/payment-methods', [PaymentMethodController::class, 'index'])->name('payment-methods.index');
        Route::get('/payment-methods/{method}', [PaymentMethodController::class, 'show'])->name('payment-methods.show');
    });
    Route::middleware('ability:payment-methods.write')->group(function () {
        Route::post('/payment-methods/setup', [PaymentMethodController::class, 'setup'])->name('payment-methods.setup');
        Route::patch('/payment-methods/{method}', [PaymentMethodController::class, 'update'])->name('payment-methods.update');
        Route::delete('/payment-methods/{method}', [PaymentMethodController::class, 'destroy'])->name('payment-methods.destroy');
    });

    Route::middleware('ability:quotes.read')->group(function () {
        Route::get('/quotes', [QuoteController::class, 'index'])->name('quotes.index');
        Route::get('/quotes/{quote}', [QuoteController::class, 'show'])->name('quotes.show');
        Route::get('/quotes/{quote}/pdf', [PdfController::class, 'quote'])->name('quotes.pdf');
    });
    Route::middleware('ability:quotes.write')->group(function () {
        Route::post('/quotes/{quote}/accept', [QuoteController::class, 'accept'])->name('quotes.accept');
        Route::post('/quotes/{quote}/decline', [QuoteController::class, 'decline'])->name('quotes.decline');
    });
});
