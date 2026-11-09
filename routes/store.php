<?php

// Public storefront (no auth): store.index, store.show.

use App\Http\Controllers\StoreController;
use Illuminate\Support\Facades\Route;

Route::get('/store', [StoreController::class, 'index'])->name('store.index');
Route::get('/store/{product:slug}', [StoreController::class, 'show'])->name('store.show');
