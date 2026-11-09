<?php

use Illuminate\Support\Facades\Route;
use Plugins\LicenseServer\Http\Controllers\AdminController;

Route::get('/', [AdminController::class, 'index'])->name('index');
Route::get('{license}', [AdminController::class, 'show'])->whereNumber('license')->name('show');
Route::patch('{license}', [AdminController::class, 'update'])->whereNumber('license')->name('update');
Route::post('{license}/revoke', [AdminController::class, 'revoke'])->whereNumber('license')->name('revoke');
Route::post('{license}/reactivate', [AdminController::class, 'reactivate'])->whereNumber('license')->name('reactivate');
Route::delete('{license}/activations/{activation}', [AdminController::class, 'removeActivation'])->whereNumber(['license', 'activation'])->name('activations.destroy');
