<?php

use App\Http\Controllers\Api\Admin\ActivityController;
use App\Http\Controllers\Api\Admin\ClientController;
use App\Http\Controllers\Api\Admin\ClientUserController;
use App\Http\Controllers\Api\Admin\DashboardController;
use App\Http\Controllers\Api\Admin\ProductController;
use App\Http\Controllers\Api\Admin\ProductGroupController;
use App\Http\Controllers\Api\Admin\ServerController;
use App\Http\Controllers\Api\Admin\ServiceController;
use App\Http\Controllers\Api\Support\BindAfterAuth;
use Illuminate\Support\Facades\Route;

Route::get('/dashboard', DashboardController::class)->middleware(['ability:dashboard.read', BindAfterAuth::class])->name('dashboard');
Route::get('/activity', [ActivityController::class, 'index'])->middleware(['ability:activity.read', BindAfterAuth::class])->name('activity.index');

// ---- clients and their portal logins ------------------------------------------------------------------------------
Route::middleware(['ability:clients.read', BindAfterAuth::class])->group(function () {
    Route::get('/clients', [ClientController::class, 'index'])->name('clients.index');
    Route::get('/clients/{client}', [ClientController::class, 'show'])->name('clients.show');
    Route::get('/clients/{client}/tax-rate', [ClientController::class, 'taxRate'])->name('clients.tax-rate');
    Route::get('/clients/{client}/users', [ClientUserController::class, 'index'])->name('clients.users.index');
    Route::get('/users/{user}', [ClientUserController::class, 'show'])->name('users.show');
});
Route::middleware(['ability:clients.write', BindAfterAuth::class])->group(function () {
    Route::post('/clients', [ClientController::class, 'store'])->name('clients.store');
    Route::patch('/clients/{client}', [ClientController::class, 'update'])->name('clients.update');
    Route::delete('/clients/{client}', [ClientController::class, 'destroy'])->name('clients.destroy');
    Route::post('/clients/{client}/users', [ClientUserController::class, 'store'])->name('clients.users.store');
    Route::patch('/users/{user}', [ClientUserController::class, 'update'])->name('users.update');
    Route::post('/users/{user}/password', [ClientUserController::class, 'setPassword'])->name('users.password');
    Route::post('/users/{user}/password-reset-link', [ClientUserController::class, 'sendResetLink'])->name('users.password-reset-link');
    Route::post('/users/{user}/activate', [ClientUserController::class, 'activate'])->name('users.activate');
    Route::post('/users/{user}/deactivate', [ClientUserController::class, 'deactivate'])->name('users.deactivate');
});
Route::post('/users/{user}/impersonate', [ClientUserController::class, 'impersonate'])->middleware(['ability:impersonation.write', BindAfterAuth::class])->name('users.impersonate');

// ---- services -----------------------------------------------------------------------------------------------------
Route::middleware(['ability:services.read', BindAfterAuth::class])->group(function () {
    Route::get('/services', [ServiceController::class, 'index'])->name('services.index');
    Route::get('/services/{service}', [ServiceController::class, 'show'])->name('services.show');
    Route::get('/services/{service}/logs', [ServiceController::class, 'logs'])->name('services.logs');
});
Route::middleware(['ability:services.write', BindAfterAuth::class])->group(function () {
    Route::post('/services', [ServiceController::class, 'store'])->name('services.store');
    Route::patch('/services/{service}', [ServiceController::class, 'update'])->name('services.update');
    Route::post('/services/{service}/activate', [ServiceController::class, 'activate'])->name('services.activate');
    Route::post('/services/{service}/suspend', [ServiceController::class, 'suspend'])->name('services.suspend');
    Route::post('/services/{service}/unsuspend', [ServiceController::class, 'unsuspend'])->name('services.unsuspend');
    Route::post('/services/{service}/terminate', [ServiceController::class, 'terminate'])->name('services.terminate');
    Route::post('/services/{service}/cancel', [ServiceController::class, 'cancel'])->name('services.cancel');
    Route::post('/services/{service}/reveal', [ServiceController::class, 'reveal'])->name('services.reveal');
    Route::post('/services/{service}/actions/{action}', [ServiceController::class, 'action'])->name('services.action');
});

// ---- catalogue: products, groups ----------------------------------------------------------------------------------
Route::middleware(['ability:products.read', BindAfterAuth::class])->group(function () {
    Route::get('/products', [ProductController::class, 'index'])->name('products.index');
    Route::get('/products/{product}', [ProductController::class, 'show'])->name('products.show');
    Route::get('/product-groups', [ProductGroupController::class, 'index'])->name('product-groups.index');
    Route::get('/product-groups/{group}', [ProductGroupController::class, 'show'])->name('product-groups.show');
});
Route::middleware(['ability:products.write', BindAfterAuth::class])->group(function () {
    Route::post('/products', [ProductController::class, 'store'])->name('products.store');
    Route::post('/products/reorder', [ProductController::class, 'reorder'])->name('products.reorder');
    Route::patch('/products/{product}', [ProductController::class, 'update'])->name('products.update');
    Route::delete('/products/{product}', [ProductController::class, 'destroy'])->name('products.destroy');
    Route::post('/product-groups', [ProductGroupController::class, 'store'])->name('product-groups.store');
    Route::post('/product-groups/reorder', [ProductGroupController::class, 'reorder'])->name('product-groups.reorder');
    Route::patch('/product-groups/{group}', [ProductGroupController::class, 'update'])->name('product-groups.update');
    Route::delete('/product-groups/{group}', [ProductGroupController::class, 'destroy'])->name('product-groups.destroy');
});

// ---- provisioning servers -----------------------------------------------------------------------------------------
Route::middleware(['ability:servers.read', BindAfterAuth::class])->group(function () {
    Route::get('/servers', [ServerController::class, 'index'])->name('servers.index');
    Route::get('/servers/{server}', [ServerController::class, 'show'])->name('servers.show');
});
Route::middleware(['ability:servers.write', BindAfterAuth::class])->group(function () {
    Route::post('/servers', [ServerController::class, 'store'])->name('servers.store');
    Route::patch('/servers/{server}', [ServerController::class, 'update'])->name('servers.update');
    Route::delete('/servers/{server}', [ServerController::class, 'destroy'])->name('servers.destroy');
    Route::post('/servers/{server}/test', [ServerController::class, 'test'])->name('servers.test');
});
