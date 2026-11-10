<?php

// Public knowledge base: kb.index, kb.search, kb.media, kb.feedback, kb.show (catch-all for categories and articles, so it is last).
// Everything is a 404 while Settings → Knowledge base is off, and needs a sign-in while guests are not allowed.

use App\Http\Controllers\KnowledgebaseController;
use Illuminate\Support\Facades\Route;

Route::middleware('kb.readable')->prefix('kb')->name('kb.')->group(function () {
    Route::get('/', [KnowledgebaseController::class, 'index'])->name('index');
    Route::get('search', [KnowledgebaseController::class, 'search'])->name('search');
    Route::get('media/{file}', [KnowledgebaseController::class, 'media'])->where('file', '[A-Za-z0-9._-]+')->name('media');
    Route::post('feedback/{article}', [KnowledgebaseController::class, 'feedback'])->whereNumber('article')->middleware('throttle:30,1')->name('feedback');
    Route::get('{path}', [KnowledgebaseController::class, 'show'])->where('path', '.*')->name('show');
});
