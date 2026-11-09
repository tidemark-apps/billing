<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** The client area needs a user attached to a client. Staff see it only while "viewing as client". */
class EnsureCustomer
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user || ! $user->is_active) {
            abort(403);
        }
        if (! $user->client_id) {
            return redirect()->route($user->isStaff() ? 'admin.dashboard' : 'login')->with('error', 'This account has no client profile.');
        }

        return $next($request);
    }
}
