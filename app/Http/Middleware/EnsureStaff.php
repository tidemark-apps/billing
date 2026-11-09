<?php

namespace App\Http\Middleware;

use App\Services\TwoFactor;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureStaff
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user || ! $user->is_active || ! $user->isStaff()) {
            abort(403, 'This area is for staff only.');
        }

        // "Require 2FA for staff": send staff without it to enrol (the security pages themselves stay reachable).
        if (app(TwoFactor::class)->staffMustEnrol($user) && ! $request->routeIs('admin.account.security.*')) {
            return redirect()->route('admin.account.security.index')->with('error', 'Two-factor authentication is required for staff. Set it up to continue.');
        }

        return $next($request);
    }
}
