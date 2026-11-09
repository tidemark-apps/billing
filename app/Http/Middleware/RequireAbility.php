<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Restrict a route to tokens that carry an ability: ->middleware('ability:invoices.write'). Tokens without a list can do everything. */
class RequireAbility
{
    public function handle(Request $request, Closure $next, string ...$abilities): Response
    {
        $token = $request->attributes->get('api_token');
        foreach ($abilities as $ability) {
            if ($token && ! $token->can($ability)) {
                return response()->json(['error' => ['code' => 'forbidden', 'message' => "This token lacks the \"$ability\" ability."]], 403);
            }
        }

        return $next($request);
    }
}
