<?php

// Client area. Loaded inside: Route::middleware(['auth','customer'])->prefix('portal')->name('portal.')

use App\Http\Controllers\Portal\ApiTokenController;
use App\Http\Controllers\Portal\DashboardController;
use App\Http\Controllers\Portal\InvoiceController;
use App\Http\Controllers\Portal\OrderController;
use App\Http\Controllers\Portal\ProfileController;
use App\Http\Controllers\Portal\QuoteController;
use App\Http\Controllers\Portal\ServiceController;
use App\Http\Controllers\StoreController;
use Illuminate\Support\Facades\Route;

Route::get('/', DashboardController::class)->name('dashboard');

Route::get('/services', [ServiceController::class, 'index'])->name('services.index');
Route::get('/services/{service}', [ServiceController::class, 'show'])->whereNumber('service')->name('services.show');
Route::post('/services/{service}/reveal', [ServiceController::class, 'reveal'])->whereNumber('service')->name('services.reveal');
Route::post('/services/{service}/actions/{action}', [ServiceController::class, 'action'])->whereNumber('service')->name('services.action');
Route::post('/services/{service}/cancel', [ServiceController::class, 'cancel'])->whereNumber('service')->name('services.cancel');

Route::get('/invoices', [InvoiceController::class, 'index'])->name('invoices.index');
Route::get('/invoices/{invoice}', [InvoiceController::class, 'show'])->whereNumber('invoice')->name('invoices.show');

Route::get('/quotes', [QuoteController::class, 'index'])->name('quotes.index');
Route::get('/quotes/{quote}', [QuoteController::class, 'show'])->whereNumber('quote')->name('quotes.show');
Route::post('/quotes/{quote}', [QuoteController::class, 'respond'])->whereNumber('quote')->name('quotes.respond');

Route::get('/store', [StoreController::class, 'portal'])->name('store');
Route::get('/order', [OrderController::class, 'create'])->name('order.create');
Route::post('/order', [OrderController::class, 'store'])->name('order.store');

Route::get('/profile', [ProfileController::class, 'edit'])->name('profile');
Route::post('/profile', [ProfileController::class, 'update'])->name('profile.update');
Route::post('/profile/password', [ProfileController::class, 'password'])->name('profile.password');

Route::get('/api', [ApiTokenController::class, 'index'])->name('api-tokens');
Route::post('/api', [ApiTokenController::class, 'store'])->name('api-tokens.store');
Route::delete('/api/{token}', [ApiTokenController::class, 'destroy'])->whereNumber('token')->name('api-tokens.destroy');

// Feature route files (routes/portal/*.php) are loaded here so features can add client-area routes without editing this file.
foreach (glob(base_path('routes/portal/*.php')) ?: [] as $file) {
    require $file;
}
