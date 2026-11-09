<?php

// Staff area. Loaded inside: Route::middleware(['auth','staff'])->prefix('admin')->name('admin.')
// Each admin area owns one file in routes/admin/ so several people can work in parallel:
//   customers.php  dashboard, clients, services, products, servers, impersonation
//   billing.php    invoices, quotes, payments, tax rates
//   system.php     settings, plugins, API tokens & webhooks
foreach (glob(__DIR__.'/admin/*.php') as $file) {
    require $file;
}
