<?php

// Customer plan changes. Loaded inside the portal group (prefix portal, name portal.).

use App\Http\Controllers\Portal\ServiceController;
use Illuminate\Support\Facades\Route;

Route::post('/services/{service}/plan-change/preview', [ServiceController::class, 'previewPlan'])->whereNumber('service')->name('services.plan-change.preview');
Route::post('/services/{service}/plan-change', [ServiceController::class, 'changePlan'])->whereNumber('service')->name('services.plan-change.store');
Route::delete('/services/{service}/plan-change/scheduled', [ServiceController::class, 'cancelPlanChange'])->whereNumber('service')->name('services.plan-change.cancel');
