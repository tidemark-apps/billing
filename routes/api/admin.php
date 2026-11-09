<?php

// Admin API: /api/v1/admin/*, staff tokens only. Abilities: <resource>.read for GET, <resource>.write for changes
// (the full list lives in App\Support\Abilities). Mirrors routes/admin/*.php, one file per area:
//   admin/customers.php  dashboard, clients and their logins, services, catalogue, servers, activity
//   admin/billing.php    invoices, quotes, payments, tax rates
//   admin/system.php     settings, plugins, tokens, webhooks, catalogues
// Features added later get their own file here (or a block in the matching one): every file is loaded automatically.
foreach (glob(__DIR__.'/admin/*.php') as $file) {
    require $file;
}
