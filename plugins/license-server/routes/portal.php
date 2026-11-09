<?php

use Illuminate\Support\Facades\Route;
use Plugins\LicenseServer\Http\Controllers\PortalController;

Route::delete('activations/{activation}', [PortalController::class, 'removeActivation'])->whereNumber('activation')->name('activations.destroy');
