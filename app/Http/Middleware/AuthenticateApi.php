<?php

namespace App\Http\Middleware;

use App\Models\ApiToken;
use App\Services\TwoFactor;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bearer-token authentication for the API.
 *   auth.api            any valid token
 *   auth.api:staff      the token's user must be staff (admin API)
 *   auth.api:customer   the token's user must be a customer with a client (client API)
 * While Settings → Security requires 2FA for staff, every call with the token of a staff user who has not enrolled is refused
 * with 403 two_factor_enrolment_required (checked before the audience, so it applies to any endpoint), except DELETE /auth/token, which revokes the token itself.
 * The token and its abilities are available as $request->attributes->get('api_token').
 */
class AuthenticateApi
{
    public function handle(Request $request, Closure $next, ?string $audience = null): Response
    {
        $plain = $request->bearerToken();
        $token = $plain ? ApiToken::findPlain($plain) : null;

        if (! $token || $token->isExpired() || ! $token->user || ! $token->user->is_active) {
            return response()->json(['error' => ['code' => 'unauthenticated', 'message' => 'Missing or invalid API token.']], 401);
        }
        $user = $token->user;
        // "Require 2FA for staff" also covers tokens that already existed or were created elsewhere (admin UI, admin API,
        // old integrations): while the setting is on, a staff token whose owner has not enrolled works for nothing.
        // Revoking the token itself stays allowed: it can only reduce access, and it lets an unenrolled person clean up.
        if (! $request->routeIs('api.client.auth.revoke') && app(TwoFactor::class)->staffMustEnrol($user)) {
            return response()->json(['error' => ['code' => 'two_factor_enrolment_required', 'message' => 'Two-factor authentication is required for staff. Sign in to the admin area and set it up (Account → Security); this token works again once it is on.']], 403);
        }
        if ($audience === 'staff' && ! $user->isStaff()) {
            return response()->json(['error' => ['code' => 'forbidden', 'message' => 'This endpoint requires a staff API token.']], 403);
        }
        if ($audience === 'customer' && (! $user->isCustomer() || ! $user->client_id)) {
            return response()->json(['error' => ['code' => 'forbidden', 'message' => 'This endpoint requires a customer API token.']], 403);
        }

        if (! $token->last_used_at || $token->last_used_at->lt(now()->subMinutes(5))) {
            $token->forceFill(['last_used_at' => now()])->saveQuietly();
        }
        Auth::setUser($user);
        $request->setUserResolver(fn () => $user);
        $request->attributes->set('api_token', $token);

        return $next($request);
    }
}
