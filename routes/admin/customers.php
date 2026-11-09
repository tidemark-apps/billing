<?php

use App\Http\Controllers\Admin\ClientController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\ImpersonateController;
use App\Http\Controllers\Admin\ProductController;
use App\Http\Controllers\Admin\ProductGroupController;
use App\Http\Controllers\Admin\ServerController;
use App\Http\Controllers\Admin\ServiceController;
use Illuminate\Support\Facades\Route;

Route::get('/', DashboardController::class)->name('dashboard');

Route::resource('clients', ClientController::class)->except('show')->names('clients');
Route::get('clients/{client}', [ClientController::class, 'show'])->name('clients.show');
Route::post('clients/{client}/contacts', [ClientController::class, 'storeContact'])->name('clients.contacts.store');
Route::post('impersonate/{user}', [ImpersonateController::class, 'start'])->name('impersonate.start');

Route::resource('services', ServiceController::class)->only(['index', 'create', 'store', 'show', 'update'])->names('services');
Route::post('services/{service}/activate', [ServiceController::class, 'activate'])->name('services.activate');
Route::post('services/{service}/suspend', [ServiceController::class, 'suspend'])->name('services.suspend');
Route::post('services/{service}/unsuspend', [ServiceController::class, 'unsuspend'])->name('services.unsuspend');
Route::post('services/{service}/terminate', [ServiceController::class, 'terminate'])->name('services.terminate');
Route::post('services/{service}/cancel', [ServiceController::class, 'cancel'])->name('services.cancel');
Route::post('services/{service}/reveal', [ServiceController::class, 'reveal'])->name('services.reveal');
Route::post('services/{service}/action', [ServiceController::class, 'action'])->name('services.action');

Route::resource('products', ProductController::class)->except('show')->names('products');
Route::post('product-groups', [ProductGroupController::class, 'store'])->name('product-groups.store');
Route::put('product-groups/{group}', [ProductGroupController::class, 'update'])->name('product-groups.update');
Route::delete('product-groups/{group}', [ProductGroupController::class, 'destroy'])->name('product-groups.destroy');

Route::resource('servers', ServerController::class)->except('show')->names('servers');
Route::post('servers/{server}/test', [ServerController::class, 'test'])->name('servers.test');
