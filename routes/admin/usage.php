<?php

use App\Http\Controllers\Admin\UsageController;
use Illuminate\Support\Facades\Route;

// Metered usage: staff can record usage by hand (the REST API and provisioning modules use App\Services\UsageService directly).
Route::post('services/{service}/usage', [UsageController::class, 'store'])->name('services.usage.store');
Route::delete('services/{service}/usage/{record}', [UsageController::class, 'destroy'])->whereNumber('record')->name('services.usage.destroy');
