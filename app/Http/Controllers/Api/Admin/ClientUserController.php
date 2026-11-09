<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\Role;
use App\Http\Controllers\Api\ApiController;
use App\Http\Resources\UserResource;
use App\Models\ApiToken;
use App\Models\Client;
use App\Models\User;
use App\Support\Abilities;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password as PasswordRule;

/**
 * Portal logins (customer users) of a client, and impersonation. Staff accounts are not managed here: the admin UI
 * has no staff management either.
 */
class ClientUserController extends ApiController
{
    public function index(Request $request, Client $client)
    {
        $query = $client->users()->orderBy('id');
        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        return $this->paginate($query, UserResource::class, $request);
    }

    public function store(Request $request, Client $client)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', PasswordRule::min(8)],
        ]);
        $user = User::create($data + ['role' => Role::Customer, 'client_id' => $client->id]);
        $this->audit('client.contact_added', $client, 'Added login '.$user->email);

        return (new UserResource($user->load('client')))->response()->setStatusCode(201);
    }

    public function show(User $user): UserResource
    {
        return new UserResource($this->login($user)->load('client'));
    }

    public function update(Request $request, User $user): UserResource
    {
        $this->login($user);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
        ]);
        $user->update($data);
        $this->audit('client.contact_updated', $user, 'Updated login '.$user->email);

        return new UserResource($user->refresh()->load('client'));
    }

    /** Set a new password. The login's API tokens are revoked so a leaked token cannot outlive the reset. */
    public function setPassword(Request $request, User $user): UserResource
    {
        $this->login($user);
        $data = $request->validate(['password' => ['required', PasswordRule::min(8)]]);
        $user->forceFill(['password' => $data['password']])->save();
        $user->apiTokens()->delete();
        $this->audit('client.contact_password_reset', $user, 'Reset the password of '.$user->email);

        return new UserResource($user->refresh()->load('client'));
    }

    /** Email the login the standard "choose a new password" link. */
    public function sendResetLink(User $user)
    {
        $this->login($user);
        $status = Password::sendResetLink(['email' => $user->email]);
        if ($status !== Password::RESET_LINK_SENT) {
            throw new \DomainException(__($status));
        }
        $this->audit('client.contact_reset_link_sent', $user, 'Sent a password reset link to '.$user->email);

        return response()->json(['data' => ['success' => true, 'message' => 'A reset link was sent to '.$user->email.'.']]);
    }

    public function activate(User $user): UserResource
    {
        return $this->setActive($user, true);
    }

    /** A deactivated login cannot sign in or use the API; its tokens are revoked. */
    public function deactivate(User $user): UserResource
    {
        return $this->setActive($user, false);
    }

    /**
     * Issue a short-lived customer token that acts as this login (the API counterpart of "View as customer").
     * The token name records who issued it; every issue is written to the activity log.
     */
    public function impersonate(Request $request, User $user)
    {
        abort_if(! $user->isCustomer() || ! $user->client_id || ! $user->is_active, 403, 'Only active client logins can be impersonated.');
        $data = $request->validate([
            'minutes' => ['nullable', 'integer', 'min:1', 'max:60'],
            'abilities' => ['nullable', 'array'],
            'abilities.*' => ['string', Abilities::rule('customer')],
        ]);
        $staff = $request->user();
        $creator = $request->attributes->get('api_token');
        foreach ($data['abilities'] ?? [] as $ability) {
            if ($creator && ! $creator->can($ability)) {
                abort(403, "This token lacks the \"$ability\" ability, so it cannot grant it.");
            }
        }
        [$token, $plain] = ApiToken::issue($user, 'Impersonation by '.$staff->email, $data['abilities'] ?? null, now()->addMinutes($data['minutes'] ?? 15));
        $this->audit('impersonation.started', $user, "{$staff->email} issued an API token as {$user->email}", ['token_id' => $token->id]);

        return response()->json(['data' => [
            'token' => $plain,
            'token_type' => 'Bearer',
            'abilities' => $token->abilities ?? ['*'],
            'expires_at' => $token->expires_at->toIso8601String(),
            'user' => (new UserResource($user->load('client')))->resolve($request),
        ]], 201);
    }

    private function setActive(User $user, bool $active): UserResource
    {
        $this->login($user);
        $user->update(['is_active' => $active]);
        if (! $active) {
            $user->apiTokens()->delete();
        }
        $this->audit($active ? 'client.contact_activated' : 'client.contact_deactivated', $user, ($active ? 'Activated' : 'Deactivated').' login '.$user->email);

        return new UserResource($user->refresh()->load('client'));
    }

    /** Only portal logins belong to this resource; anything else (staff, orphans) is "not found". */
    private function login(User $user): User
    {
        abort_unless($user->isCustomer() && $user->client_id, 404, 'The requested resource was not found.');

        return $user;
    }
}
