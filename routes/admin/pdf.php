<?php

use App\Http\Controllers\Pdf\AdminPdfController;
use App\Http\Controllers\Pdf\PdfFontsController;
use Illuminate\Support\Facades\Route;

// PDF downloads for staff. Loaded inside the admin group (auth + staff, prefix /admin, names admin.*).
Route::get('invoices/{invoice}/pdf', [AdminPdfController::class, 'invoice'])->whereNumber('invoice')->name('invoices.pdf');
Route::get('quotes/{quote}/pdf', [AdminPdfController::class, 'quote'])->whereNumber('quote')->name('quotes.pdf');
Route::post('settings/system/pdf-fonts', [PdfFontsController::class, 'install'])->name('settings.pdf-fonts');
