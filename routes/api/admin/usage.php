<?php

use App\Http\Controllers\Api\Admin\UsageController;
use App\Http\Controllers\Api\Support\BindAfterAuth;
use Illuminate\Support\Facades\Route;

// ---- metered usage billing: product meters and the usage recorded against services --------------------------------
Route::middleware(['ability:usage.read', BindAfterAuth::class])->group(function () {
    Route::get('/products/{product}/meters', [UsageController::class, 'meters'])->name('products.meters.index');
    Route::get('/products/{product}/meters/{meter}', [UsageController::class, 'showMeter'])->name('products.meters.show');
    Route::get('/services/{service}/usage', [UsageController::class, 'show'])->name('services.usage.index');
});
Route::middleware(['ability:usage.write', BindAfterAuth::class])->group(function () {
    Route::post('/products/{product}/meters', [UsageController::class, 'storeMeter'])->name('products.meters.store');
    Route::patch('/products/{product}/meters/{meter}', [UsageController::class, 'updateMeter'])->name('products.meters.update');
    Route::delete('/products/{product}/meters/{meter}', [UsageController::class, 'destroyMeter'])->name('products.meters.destroy');
    Route::post('/services/{service}/usage', [UsageController::class, 'record'])->name('services.usage.store');
    Route::delete('/services/{service}/usage/{record}', [UsageController::class, 'destroyRecord'])->name('services.usage.destroy');
});
