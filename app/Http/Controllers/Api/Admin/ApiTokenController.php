<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Http\Resources\ApiTokenResource;
use App\Models\ApiToken;
use App\Services\TwoFactor;
use App\Support\Abilities;
use Illuminate\Http\Request;

/**
 * API token management for administrators, like Admin → API & webhooks. Tokens are created for the calling admin
 * (the UI has no "create for someone else"), and a token can never be given more abilities than the token creating it.
 */
class ApiTokenController extends ApiController
{
    /** Your own tokens; with user_id (or all=1) an administrator can list other people's, including customers'. */
    public function index(Request $request)
    {
        $this->requireAdmin($request, 'Only administrators can manage API tokens.');
        $query = ApiToken::with('user')->latest('id');
        if ($request->filled('user_id')) {
            $query->where('user_id', $request->integer('user_id'));
        } elseif (! $request->boolean('all')) {
            $query->where('user_id', $request->user()->id);
        }

        return $this->paginate($query, ApiTokenResource::class, $request);
    }

    public function store(Request $request)
    {
        $this->requireAdmin($request, 'Only administrators can manage API tokens.');
        if (app(TwoFactor::class)->staffMustEnrol($request->user())) {
            return response()->json(['error' => ['code' => 'two_factor_enrolment_required', 'message' => 'Two-factor authentication is required for staff before API tokens can be created.']], 403);
        }
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'abilities' => ['nullable', 'array'],
            'abilities.*' => ['string', Abilities::rule('staff')],
            'expires_in_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
        ]);
        $creator = $request->attributes->get('api_token');
        $abilities = isset($data['abilities']) ? array_values(array_unique($data['abilities'])) : null;
        if ($creator && $creator->abilities !== null) {
            if ($abilities === null) {
                abort(403, 'This token has a limited set of abilities, so it cannot create a full-access token. Send "abilities".');
            }
            foreach ($abilities as $ability) {
                if (! $creator->can($ability)) {
                    abort(403, "This token lacks the \"$ability\" ability, so it cannot grant it.");
                }
            }
        }
        [$token, $plain] = ApiToken::issue($request->user(), $data['name'], $abilities, isset($data['expires_in_days']) ? now()->addDays((int) $data['expires_in_days']) : null);
        $this->audit('api.token.created', null, 'Created API token "'.$data['name'].'"');

        return response()->json(['data' => (new ApiTokenResource($token->load('user')))->resolve($request) + ['token' => $plain, 'token_type' => 'Bearer']], 201);
    }

    public function show(Request $request, ApiToken $token): ApiTokenResource
    {
        $this->requireAdmin($request, 'Only administrators can manage API tokens.');

        return new ApiTokenResource($token->load('user'));
    }

    public function destroy(Request $request, ApiToken $token)
    {
        $this->requireAdmin($request, 'Only administrators can manage API tokens.');
        $this->audit('api.token.revoked', null, 'Revoked API token "'.$token->name.'"');
        $token->delete();

        return response()->noContent();
    }
}
