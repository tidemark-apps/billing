<?php

// REST API, served under /api/v1 with the "api" middleware group. See docs/api.md and /api/v1/openapi.json.

use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->json(['name' => config('billing.name'), 'version' => config('billing.version'), 'api' => 'v1']))->name('api.index');

Route::get('/openapi.json', function () {
    $spec = json_decode((string) file_get_contents(resource_path('openapi.json')), true);
    $spec['servers'] = [['url' => url('/api/v1')]];

    return response()->json($spec);
})->name('api.openapi');

Route::name('api.client.')->group(base_path('routes/api/client.php'));
// Admin API. Route-model binding is removed from the group and re-added after the ability check on every route group
// (see BindAfterAuth), so unknown ids never answer 404 before 401/403.
Route::prefix('admin')->name('api.admin.')
    ->withoutMiddleware(SubstituteBindings::class)
    ->middleware(['auth.api:staff', 'throttle:api'])
    ->group(base_path('routes/api/admin.php'));
