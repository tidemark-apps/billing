<?php

// Trials and plan changes on a service. Loaded inside the admin group (prefix admin, name admin.).

use App\Http\Controllers\Admin\ServiceController;
use Illuminate\Support\Facades\Route;

Route::post('services/{service}/trial/extend', [ServiceController::class, 'extendTrial'])->name('services.trial.extend');
Route::post('services/{service}/trial/end', [ServiceController::class, 'endTrial'])->name('services.trial.end');
Route::post('services/{service}/plan-change/preview', [ServiceController::class, 'previewPlanChange'])->name('services.plan-change.preview');
Route::post('services/{service}/plan-change', [ServiceController::class, 'changePlan'])->name('services.plan-change.store');
Route::delete('services/{service}/plan-change/scheduled', [ServiceController::class, 'cancelScheduledChange'])->name('services.plan-change.cancel');
