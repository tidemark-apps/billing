<?php

namespace App\Http\Controllers\Api\Client;

use App\Http\Controllers\Api\ApiController;
use App\Http\Resources\UserResource;
use App\Models\ApiToken;
use App\Models\User;
use App\Services\Registration;
use App\Services\TwoFactor;
use App\Support\Abilities;
use App\Support\Activity;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends ApiController
{
    /**
     * Exchange email + password for a bearer token. Staff get a staff token, customers a customer token.
     *
     * Users with two-factor authentication must also send `otp`: a 6-digit authenticator code or an unused recovery code
     * (otp_required / invalid_otp, both 401). Attempts are throttled per user and IP. The API never sets a "remember this
     * device" cookie. While Settings → Security requires 2FA for staff, staff who have not enrolled get no token
     * (two_factor_enrolment_required, 403): they must set it up in the admin area first.
     */
    public function token(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'name' => ['nullable', 'string', 'max:100'],
            'abilities' => ['nullable', 'array'],
            'abilities.*' => ['string', 'max:64'],
            'expires_in_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'otp' => ['nullable', 'string', 'max:64'],
        ]);
        $user = User::where('email', $data['email'])->first();
        if (! $user || ! $user->is_active || ! Hash::check($data['password'], $user->password)) {
            return response()->json(['error' => ['code' => 'invalid_credentials', 'message' => 'These credentials do not match our records.']], 401);
        }
        $twoFactor = app(TwoFactor::class);
        if ($twoFactor->staffMustEnrol($user)) {
            return response()->json(['error' => ['code' => 'two_factor_enrolment_required', 'message' => 'Two-factor authentication is required for staff. Sign in to the admin area and set it up (Account → Security) before requesting API tokens.']], 403);
        }
        if (! empty($data['abilities'])) {
            $unknown = array_diff($data['abilities'], Abilities::forUser($user));
            if ($unknown) {
                throw ValidationException::withMessages(['abilities' => 'Unknown abilities: '.implode(', ', $unknown).'. Valid abilities are listed in docs/api.md and GET /admin/abilities.']);
            }
        }
        if ($user->hasTwoFactor() && ($failure = $this->checkOtp($user, $data['otp'] ?? null, $twoFactor))) {
            return $failure;
        }

        return $this->issue($user, $data['name'] ?? 'API token', $data['abilities'] ?? null, isset($data['expires_in_days']) ? now()->addDays($data['expires_in_days']) : null);
    }

    /** Null when the code is right; otherwise the error response. A missing code is not an attempt, a wrong one is. */
    private function checkOtp(User $user, ?string $otp, TwoFactor $twoFactor): ?JsonResponse
    {
        if ($otp === null || trim($otp) === '') {
            return response()->json(['error' => ['code' => 'otp_required', 'message' => 'This account uses two-factor authentication. Send the "otp" field with an authenticator code or a recovery code.']], 401);
        }
        $key = 'two-factor-api:'.$user->id.'|'.request()->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            return response()->json(['error' => ['code' => 'too_many_requests', 'message' => 'Too many two-factor attempts. Retry in '.RateLimiter::availableIn($key).' seconds.']], 429, ['Retry-After' => (string) RateLimiter::availableIn($key)]);
        }
        if (! $twoFactor->verify($user, $otp)) {
            RateLimiter::hit($key, 300);

            return response()->json(['error' => ['code' => 'invalid_otp', 'message' => 'That code is not valid or was already used.']], 401);
        }
        RateLimiter::clear($key);
        if (! preg_match('/^\d{6}$/', preg_replace('/[\s-]/', '', $otp) ?? '')) {
            Activity::log('two_factor.recovery_code_used', $user, "{$user->email} requested an API token with a recovery code", ['via' => 'api']);
        }

        return null;
    }

    public function register(Request $request, Registration $registration): JsonResponse
    {
        if (! config('billing.registration') || ! Settings::bool('general.registration', true)) {
            return response()->json(['error' => ['code' => 'registration_closed', 'message' => 'Registration is closed.']], 403);
        }
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'company_name' => ['nullable', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', Password::min(8)],
            'country' => ['nullable', 'string', 'size:2'],
            'currency' => ['nullable', 'string', 'size:3'],
        ]);
        $result = $registration->createClient(array_filter([
            'name' => $data['name'], 'company_name' => $data['company_name'] ?? null, 'email' => $data['email'],
            'country' => isset($data['country']) ? strtoupper($data['country']) : null,
            'currency' => isset($data['currency']) ? strtoupper($data['currency']) : null,
        ]), $data['password']);

        return $this->issue($result['user'], 'Registration');
    }

    public function revoke(Request $request)
    {
        $request->attributes->get('api_token')->delete();

        return response()->noContent();
    }

    /** @param  array<int, string>|null  $abilities */
    private function issue(User $user, string $name, ?array $abilities = null, ?\DateTimeInterface $expires = null): JsonResponse
    {
        [$token, $plain] = ApiToken::issue($user, $name, $abilities, $expires);
        $user->load('client');

        return response()->json(['data' => [
            'token' => $plain,
            'token_type' => 'Bearer',
            'abilities' => $token->abilities ?? ['*'],
            'expires_at' => $token->expires_at?->toIso8601String(),
            'user' => (new UserResource($user))->resolve(request()),
        ]], 201);
    }
}
