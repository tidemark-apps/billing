<?php

use Illuminate\Support\Facades\Route;

Route::get('/', fn () => view('demo::page'))->name('index');
