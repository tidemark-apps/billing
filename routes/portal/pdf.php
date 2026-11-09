<?php

use App\Http\Controllers\Pdf\PortalPdfController;
use Illuminate\Support\Facades\Route;

// PDF downloads for customers. Loaded inside the portal group (auth + customer, prefix /portal, names portal.*).
Route::get('/invoices/{invoice}/pdf', [PortalPdfController::class, 'invoicePdf'])->whereNumber('invoice')->name('invoices.pdf');
Route::get('/quotes/{quote}/pdf', [PortalPdfController::class, 'quotePdf'])->whereNumber('quote')->name('quotes.pdf');
