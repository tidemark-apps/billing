<?php

use App\Http\Controllers\Admin\ApiController;
use App\Http\Controllers\Admin\PluginController;
use App\Http\Controllers\Admin\SettingsController;
use Illuminate\Support\Facades\Route;

// ---- settings --------------------------------------------------------------------------------------------------
Route::get('settings/{section?}', [SettingsController::class, 'show'])->where('section', 'general|billing|email|payments|security|support|knowledgebase|system')->name('settings.index');
Route::put('settings/{section}', [SettingsController::class, 'update'])->where('section', 'general|billing|email|payments|security|support|knowledgebase|system')->name('settings.update');
Route::post('settings/email/test', [SettingsController::class, 'testEmail'])->name('settings.email.test');
Route::post('settings/system/cron-token', [SettingsController::class, 'regenerateCronToken'])->name('settings.cron-token');
Route::post('settings/system/run', [SettingsController::class, 'runBilling'])->name('settings.run');

// ---- plugins ---------------------------------------------------------------------------------------------------
Route::get('plugins', [PluginController::class, 'index'])->name('plugins.index');
Route::post('plugins/upload', [PluginController::class, 'upload'])->name('plugins.upload');
Route::get('plugins/upload/{token}', [PluginController::class, 'confirm'])->where('token', '[a-f0-9]{32}')->name('plugins.upload.confirm');
Route::post('plugins/upload/{token}', [PluginController::class, 'install'])->where('token', '[a-f0-9]{32}')->name('plugins.upload.install');
Route::delete('plugins/{slug}', [PluginController::class, 'destroy'])->name('plugins.destroy');
Route::post('plugins/{slug}/enable', [PluginController::class, 'enable'])->name('plugins.enable');
Route::post('plugins/{slug}/disable', [PluginController::class, 'disable'])->name('plugins.disable');
Route::get('plugins/{slug}/settings', [PluginController::class, 'settings'])->name('plugins.settings');
Route::put('plugins/{slug}/settings', [PluginController::class, 'update'])->name('plugins.settings.update');

// ---- API tokens & webhooks -------------------------------------------------------------------------------------
Route::get('api', [ApiController::class, 'index'])->name('api.index');
Route::post('api/tokens', [ApiController::class, 'storeToken'])->name('api.tokens.store');
Route::delete('api/tokens/{token}', [ApiController::class, 'destroyToken'])->name('api.tokens.destroy');
Route::post('api/webhooks', [ApiController::class, 'storeWebhook'])->name('api.webhooks.store');
Route::get('api/webhooks/{webhook}', [ApiController::class, 'showWebhook'])->name('api.webhooks.show');
Route::put('api/webhooks/{webhook}', [ApiController::class, 'updateWebhook'])->name('api.webhooks.update');
Route::delete('api/webhooks/{webhook}', [ApiController::class, 'destroyWebhook'])->name('api.webhooks.destroy');
Route::post('api/webhooks/{webhook}/test', [ApiController::class, 'testWebhook'])->name('api.webhooks.test');
