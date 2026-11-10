<?php

// Support tickets, departments and custom fields. Loaded inside the admin group via routes/admin.php.
// Everything here is a plain 404 while Settings → Support → "Enable support tickets" is off.

use App\Http\Controllers\Admin\DepartmentController;
use App\Http\Controllers\Admin\TicketController;
use App\Http\Controllers\Admin\TicketFieldController;
use App\Http\Middleware\EnsureAdmin;
use Illuminate\Support\Facades\Route;

Route::middleware('support')->group(function () {
    Route::prefix('tickets')->name('tickets.')->group(function () {
        Route::get('/', [TicketController::class, 'index'])->name('index');
        Route::get('create', [TicketController::class, 'create'])->name('create');
        Route::post('/', [TicketController::class, 'store'])->name('store');
        Route::get('{ticket}', [TicketController::class, 'show'])->whereNumber('ticket')->name('show');
        Route::post('{ticket}/reply', [TicketController::class, 'reply'])->whereNumber('ticket')->name('reply');
        Route::post('{ticket}/note', [TicketController::class, 'note'])->whereNumber('ticket')->name('note');
        Route::post('{ticket}/status', [TicketController::class, 'status'])->whereNumber('ticket')->name('status');
        Route::post('{ticket}/claim', [TicketController::class, 'claim'])->whereNumber('ticket')->name('claim');
        Route::post('{ticket}/assign', [TicketController::class, 'assign'])->whereNumber('ticket')->name('assign');
        Route::post('{ticket}/lock', [TicketController::class, 'lock'])->whereNumber('ticket')->name('lock');
        Route::post('{ticket}/unlock', [TicketController::class, 'unlock'])->whereNumber('ticket')->name('unlock');
        Route::post('{ticket}/priority', [TicketController::class, 'priority'])->whereNumber('ticket')->name('priority');
        Route::post('{ticket}/transfer', [TicketController::class, 'transfer'])->whereNumber('ticket')->name('transfer');
        Route::put('{ticket}/fields', [TicketController::class, 'fields'])->whereNumber('ticket')->name('fields');
        Route::get('{ticket}/attachments/{attachment}', [TicketController::class, 'download'])->whereNumber(['ticket', 'attachment'])->name('attachments.download');
        Route::delete('{ticket}', [TicketController::class, 'destroy'])->whereNumber('ticket')->name('destroy');
    });

    Route::middleware(EnsureAdmin::class)->group(function () {
        Route::prefix('departments')->name('departments.')->group(function () {
            Route::get('/', [DepartmentController::class, 'index'])->name('index');
            Route::get('create', [DepartmentController::class, 'create'])->name('create');
            Route::post('/', [DepartmentController::class, 'store'])->name('store');
            Route::get('{department}/edit', [DepartmentController::class, 'edit'])->whereNumber('department')->name('edit');
            Route::put('{department}', [DepartmentController::class, 'update'])->whereNumber('department')->name('update');
            Route::match(['post', 'put'], '{department}/test', [DepartmentController::class, 'test'])->whereNumber('department')->name('test');
            Route::delete('{department}', [DepartmentController::class, 'destroy'])->whereNumber('department')->name('destroy');
        });
        Route::prefix('ticket-fields')->name('ticket-fields.')->group(function () {
            Route::get('/', [TicketFieldController::class, 'index'])->name('index');
            Route::get('create', [TicketFieldController::class, 'create'])->name('create');
            Route::post('/', [TicketFieldController::class, 'store'])->name('store');
            Route::get('{field}/edit', [TicketFieldController::class, 'edit'])->whereNumber('field')->name('edit');
            Route::put('{field}', [TicketFieldController::class, 'update'])->whereNumber('field')->name('update');
            Route::delete('{field}', [TicketFieldController::class, 'destroy'])->whereNumber('field')->name('destroy');
        });
    });
});
