<?php

use App\Http\Controllers\Admin\ImpersonateController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\CheckoutAccountController;
use App\Http\Controllers\CronController;
use App\Http\Controllers\GatewayWebhookController;
use App\Http\Controllers\InstallController;
use App\Http\Controllers\PayController;
use App\Http\Controllers\Pdf\PublicPdfController;
use App\Http\Controllers\PublicDocumentController;
use Illuminate\Support\Facades\Route;

/*
 * Route name contract (other route files and views depend on these):
 *   admin.*   routes/admin.php   staff area, prefix /admin
 *   portal.*  routes/portal.php  client area, prefix /portal (plus the public store: store.index, store.show)
 *   api.*     routes/api.php     REST API, prefix /api/v1
 * This file holds everything shared: install, auth, link-shared invoices/quotes, paying, gateway webhooks, cron.
 */

Route::get('/', fn () => auth()->check() ? redirect()->route(auth()->user()->isStaff() ? 'admin.dashboard' : 'portal.dashboard') : (Route::has('store.index') ? redirect()->route('store.index') : redirect()->route('login')))->name('home');

Route::get('/install', [InstallController::class, 'show'])->name('install');
Route::post('/install', [InstallController::class, 'store'])->name('install.store');

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login']);
    Route::get('/two-factor-challenge', [AuthController::class, 'showChallenge'])->name('two-factor.challenge');
    Route::post('/two-factor-challenge', [AuthController::class, 'challenge'])->name('two-factor.verify');
    Route::get('/register', [AuthController::class, 'showRegister'])->name('register');
    Route::post('/register', [AuthController::class, 'register']);
    Route::get('/forgot-password', [AuthController::class, 'showForgot'])->name('password.request');
    Route::post('/forgot-password', [AuthController::class, 'sendReset'])->name('password.email');
    Route::get('/reset-password/{token}', [AuthController::class, 'showReset'])->name('password.reset');
    Route::post('/reset-password', [AuthController::class, 'reset'])->name('password.update');
});
// The account step of checkout. Outside the guest group: a signed-in customer is sent on to the review step instead of home.
Route::get('/checkout/account', [CheckoutAccountController::class, 'show'])->name('checkout.account');
Route::post('/checkout/account', [CheckoutAccountController::class, 'store'])->middleware('throttle:10,1')->name('checkout.account.store');
Route::get('/login/link/{user}', [AuthController::class, 'loginLink'])->middleware('throttle:20,1')->name('login.link');
Route::get('/sso', [AuthController::class, 'sso'])->middleware('throttle:20,1')->name('sso');
Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

Route::get('/invoice/{invoice:uuid}', [PublicDocumentController::class, 'invoice'])->name('invoice.public');
Route::get('/quote/{quote:uuid}', [PublicDocumentController::class, 'quote'])->name('quote.public');
Route::post('/quote/{quote:uuid}', [PublicDocumentController::class, 'respond'])->name('quote.respond');
Route::get('/invoice/{invoice:uuid}/pdf', [PublicPdfController::class, 'invoice'])->name('invoice.public.pdf')->middleware('throttle:60,1');
Route::get('/quote/{quote:uuid}/pdf', [PublicPdfController::class, 'quote'])->name('quote.public.pdf')->middleware('throttle:60,1');

Route::post('/pay/{invoice:uuid}', [PayController::class, 'start'])->name('pay.start')->middleware('throttle:20,1');
Route::get('/pay/{invoice:uuid}/return/{gateway}', [PayController::class, 'complete'])->name('pay.return');
Route::get('/pay/{invoice:uuid}/cancel', [PayController::class, 'cancel'])->name('pay.cancel');

Route::post('/webhooks/gateways/{gateway}', GatewayWebhookController::class)->name('webhooks.gateway');
Route::get('/cron/{token}', CronController::class)->name('cron');

// Stopping impersonation happens while signed in as the customer, so it sits outside the staff-only admin group.
Route::post('/impersonate/stop', [ImpersonateController::class, 'stop'])->middleware('auth')->name('admin.impersonate.stop');

Route::middleware(['auth', 'staff'])->prefix('admin')->name('admin.')->group(base_path('routes/admin.php'));
Route::middleware(['auth', 'customer'])->prefix('portal')->name('portal.')->group(base_path('routes/portal.php'));
// public storefront routes (no auth) live in routes/portal.php's sibling file
Route::group([], base_path('routes/store.php'));
// public knowledge base (no auth unless Settings → Knowledge base turns guest access off): kb.*
Route::group([], base_path('routes/kb.php'));
