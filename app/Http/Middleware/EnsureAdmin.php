<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Administrators only (use after `staff`): ->middleware(EnsureAdmin::class). */
class EnsureAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user || ! $user->is_active || ! $user->isAdmin()) {
            abort(403, 'This area is for administrators only.');
        }

        return $next($request);
    }
}
