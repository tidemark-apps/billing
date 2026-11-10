<?php

use App\Http\Controllers\Api\Admin\ApiTokenController;
use App\Http\Controllers\Api\Admin\MetaController;
use App\Http\Controllers\Api\Admin\PluginController;
use App\Http\Controllers\Api\Admin\SettingsController;
use App\Http\Controllers\Api\Admin\WebhookController;
use App\Http\Controllers\Api\Support\BindAfterAuth;
use Illuminate\Support\Facades\Route;

// ---- about the caller and catalogues (any staff token) ------------------------------------------------------------
Route::get('/me', [MetaController::class, 'me'])->name('me');
Route::get('/abilities', [MetaController::class, 'abilities'])->name('abilities.index');
Route::get('/modules', [MetaController::class, 'modules'])->middleware(['ability:products.read', BindAfterAuth::class])->name('modules.index');
Route::get('/events', [MetaController::class, 'events'])->middleware(['ability:webhooks.read', BindAfterAuth::class])->name('events.index');

// ---- settings (administrators) ------------------------------------------------------------------------------------
Route::middleware(['ability:settings.read', BindAfterAuth::class])->group(function () {
    Route::get('/settings', [SettingsController::class, 'index'])->name('settings.index');
    Route::get('/settings/{section}', [SettingsController::class, 'show'])->where('section', 'general|billing|email|autopay|security|payments|support|system')->name('settings.show');
});
Route::middleware(['ability:settings.write', BindAfterAuth::class])->group(function () {
    Route::put('/settings/{section}', [SettingsController::class, 'update'])->where('section', 'general|billing|email|autopay|security|payments|support')->name('settings.update');
    Route::post('/settings/email/test', [SettingsController::class, 'testEmail'])->name('settings.email.test');
    Route::post('/settings/system/cron-token', [SettingsController::class, 'regenerateCronToken'])->name('settings.cron-token');
    Route::post('/settings/system/run', [SettingsController::class, 'runBilling'])->name('settings.run');
});

// ---- plugins ------------------------------------------------------------------------------------------------------
Route::middleware(['ability:plugins.read', BindAfterAuth::class])->group(function () {
    Route::get('/plugins', [PluginController::class, 'index'])->name('plugins.index');
    Route::get('/plugins/{slug}', [PluginController::class, 'show'])->name('plugins.show');
    Route::get('/plugins/{slug}/settings', [PluginController::class, 'settings'])->name('plugins.settings');
});
Route::middleware(['ability:plugins.write', BindAfterAuth::class])->group(function () {
    Route::post('/plugins/{slug}/enable', [PluginController::class, 'enable'])->name('plugins.enable');
    Route::post('/plugins/{slug}/disable', [PluginController::class, 'disable'])->name('plugins.disable');
    Route::put('/plugins/{slug}/settings', [PluginController::class, 'updateSettings'])->name('plugins.settings.update');
});

// ---- API tokens (administrators) ----------------------------------------------------------------------------------
Route::middleware(['ability:api-tokens.read', BindAfterAuth::class])->group(function () {
    Route::get('/api-tokens', [ApiTokenController::class, 'index'])->name('api-tokens.index');
    Route::get('/api-tokens/{token}', [ApiTokenController::class, 'show'])->name('api-tokens.show');
});
Route::middleware(['ability:api-tokens.write', BindAfterAuth::class])->group(function () {
    Route::post('/api-tokens', [ApiTokenController::class, 'store'])->name('api-tokens.store');
    Route::delete('/api-tokens/{token}', [ApiTokenController::class, 'destroy'])->name('api-tokens.destroy');
});

// ---- outbound webhooks --------------------------------------------------------------------------------------------
Route::middleware(['ability:webhooks.read', BindAfterAuth::class])->group(function () {
    Route::get('/webhooks', [WebhookController::class, 'index'])->name('webhooks.index');
    Route::get('/webhooks/{webhook}', [WebhookController::class, 'show'])->name('webhooks.show');
    Route::get('/webhooks/{webhook}/deliveries', [WebhookController::class, 'deliveries'])->name('webhooks.deliveries');
});
Route::middleware(['ability:webhooks.write', BindAfterAuth::class])->group(function () {
    Route::post('/webhooks', [WebhookController::class, 'store'])->name('webhooks.store');
    Route::patch('/webhooks/{webhook}', [WebhookController::class, 'update'])->name('webhooks.update');
    Route::delete('/webhooks/{webhook}', [WebhookController::class, 'destroy'])->name('webhooks.destroy');
    Route::post('/webhooks/{webhook}/test', [WebhookController::class, 'test'])->name('webhooks.test');
});
