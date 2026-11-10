<?php

// Support tickets in the client area. Loaded by routes/portal.php inside the portal group (names are portal.*).

use App\Http\Controllers\Portal\TicketController;
use Illuminate\Support\Facades\Route;

Route::middleware('support')->prefix('tickets')->name('tickets.')->group(function () {
    Route::get('/', [TicketController::class, 'index'])->name('index');
    Route::get('/new', [TicketController::class, 'create'])->name('create');
    Route::post('/', [TicketController::class, 'store'])->name('store');
    Route::get('/{ticket}', [TicketController::class, 'show'])->whereUuid('ticket')->name('show');
    Route::post('/{ticket}/reply', [TicketController::class, 'reply'])->whereUuid('ticket')->name('reply');
    Route::post('/{ticket}/close', [TicketController::class, 'close'])->whereUuid('ticket')->name('close');
    Route::get('/{ticket}/attachments/{attachment}', [TicketController::class, 'download'])->whereUuid(['ticket', 'attachment'])->name('attachments.download');
});
