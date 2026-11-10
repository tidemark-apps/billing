<?php

// Knowledge base management. Loaded inside the admin group via routes/admin.php.
// Everything here is a plain 404 while Settings → Knowledge base → "Enable the knowledge base" is off.

use App\Http\Controllers\Admin\KbArticleController;
use App\Http\Controllers\Admin\KbCategoryController;
use Illuminate\Support\Facades\Route;

Route::middleware('kb.enabled')->prefix('kb')->name('kb.')->group(function () {
    Route::get('/', [KbArticleController::class, 'index'])->name('index');

    Route::get('articles/create', [KbArticleController::class, 'create'])->name('articles.create');
    Route::post('articles', [KbArticleController::class, 'store'])->name('articles.store');
    Route::get('articles/{article}/edit', [KbArticleController::class, 'edit'])->whereNumber('article')->name('articles.edit');
    Route::put('articles/{article}', [KbArticleController::class, 'update'])->whereNumber('article')->name('articles.update');
    Route::post('articles/{article}/move', [KbArticleController::class, 'move'])->whereNumber('article')->name('articles.move');
    Route::delete('articles/{article}', [KbArticleController::class, 'destroy'])->whereNumber('article')->name('articles.destroy');
    Route::post('upload', [KbArticleController::class, 'upload'])->middleware('throttle:60,1')->name('upload');

    Route::get('categories/create', [KbCategoryController::class, 'create'])->name('categories.create');
    Route::post('categories', [KbCategoryController::class, 'store'])->name('categories.store');
    Route::get('categories/{category}/edit', [KbCategoryController::class, 'edit'])->whereNumber('category')->name('categories.edit');
    Route::put('categories/{category}', [KbCategoryController::class, 'update'])->whereNumber('category')->name('categories.update');
    Route::post('categories/{category}/move', [KbCategoryController::class, 'move'])->whereNumber('category')->name('categories.move');
    Route::delete('categories/{category}', [KbCategoryController::class, 'destroy'])->whereNumber('category')->name('categories.destroy');
});
