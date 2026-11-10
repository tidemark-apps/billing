<?php

use App\Http\Controllers\Api\Admin\TicketController;
use App\Http\Controllers\Api\Admin\TicketDepartmentController;
use App\Http\Controllers\Api\Admin\TicketFieldController;
use App\Http\Controllers\Api\Support\BindAfterAuth;
use Illuminate\Support\Facades\Route;

// ---- support tickets, departments and custom fields ---------------------------------------------------------------
// Every route is a plain 404 while Settings → Support → "Enable support tickets" is off. `support` comes after the ability
// check so a token without the ability still gets 403 first. Ticket visibility (departments, assignment) is enforced in the controller.
Route::middleware(['ability:tickets.read', 'support', BindAfterAuth::class])->group(function () {
    Route::get('/tickets', [TicketController::class, 'index'])->name('tickets.index');
    Route::get('/tickets/{ticket}', [TicketController::class, 'show'])->name('tickets.show');
    Route::get('/tickets/{ticket}/attachments/{attachment}', [TicketController::class, 'download'])->name('tickets.attachments.download');
});
Route::middleware(['ability:tickets.write', 'support', BindAfterAuth::class])->group(function () {
    Route::post('/tickets', [TicketController::class, 'store'])->name('tickets.store');
    Route::patch('/tickets/{ticket}', [TicketController::class, 'update'])->name('tickets.update');
    Route::delete('/tickets/{ticket}', [TicketController::class, 'destroy'])->name('tickets.destroy');
    Route::post('/tickets/{ticket}/replies', [TicketController::class, 'reply'])->name('tickets.reply');
    Route::post('/tickets/{ticket}/notes', [TicketController::class, 'note'])->name('tickets.note');
    Route::post('/tickets/{ticket}/assign', [TicketController::class, 'assign'])->name('tickets.assign');
    Route::post('/tickets/{ticket}/claim', [TicketController::class, 'claim'])->name('tickets.claim');
    Route::post('/tickets/{ticket}/lock', [TicketController::class, 'lock'])->name('tickets.lock');
    Route::post('/tickets/{ticket}/unlock', [TicketController::class, 'unlock'])->name('tickets.unlock');
    Route::post('/tickets/{ticket}/close', [TicketController::class, 'close'])->name('tickets.close');
    Route::post('/tickets/{ticket}/reopen', [TicketController::class, 'reopen'])->name('tickets.reopen');
    Route::post('/tickets/{ticket}/transfer', [TicketController::class, 'transfer'])->name('tickets.transfer');
});
Route::middleware(['ability:support.read', 'support', BindAfterAuth::class])->group(function () {
    Route::get('/ticket-departments', [TicketDepartmentController::class, 'index'])->name('ticket-departments.index');
    Route::get('/ticket-departments/{department}', [TicketDepartmentController::class, 'show'])->name('ticket-departments.show');
    Route::get('/ticket-fields', [TicketFieldController::class, 'index'])->name('ticket-fields.index');
    Route::get('/ticket-fields/{field}', [TicketFieldController::class, 'show'])->name('ticket-fields.show');
});
Route::middleware(['ability:support.write', 'support', BindAfterAuth::class])->group(function () {
    Route::post('/ticket-departments', [TicketDepartmentController::class, 'store'])->name('ticket-departments.store');
    Route::patch('/ticket-departments/{department}', [TicketDepartmentController::class, 'update'])->name('ticket-departments.update');
    Route::delete('/ticket-departments/{department}', [TicketDepartmentController::class, 'destroy'])->name('ticket-departments.destroy');
    Route::post('/ticket-fields', [TicketFieldController::class, 'store'])->name('ticket-fields.store');
    Route::patch('/ticket-fields/{field}', [TicketFieldController::class, 'update'])->name('ticket-fields.update');
    Route::delete('/ticket-fields/{field}', [TicketFieldController::class, 'destroy'])->name('ticket-fields.destroy');
});
