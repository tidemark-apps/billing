<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\Role;
use App\Http\Controllers\Api\ApiController;
use App\Http\Resources\StaffUserResource;
use App\Models\User;
use App\Services\StaffAccounts;
use App\Services\TwoFactor;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password as PasswordRule;

/**
 * Staff accounts (admin and staff roles), the API counterpart of Admin → Staff. Administrators only, on top of the
 * `staff-users.read` / `staff-users.write` abilities. Customers' logins are under /users and /clients/{id}/users.
 */
class StaffUserController extends ApiController
{
    private const ONLY_ADMINS = 'Only administrators can manage staff users.';

    public function __construct(private readonly StaffAccounts $staff) {}

    public function index(Request $request)
    {
        $this->requireAdmin($request, self::ONLY_ADMINS);
        $query = User::with('departments')->whereIn('role', [Role::Admin, Role::Staff])->orderBy('id');
        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }
        if ($request->filled('role') && in_array($request->query('role'), ['admin', 'staff'], true)) {
            $query->where('role', $request->query('role'));
        }

        return $this->paginate($query, StaffUserResource::class, $request);
    }

    public function show(Request $request, User $user): StaffUserResource
    {
        $this->requireAdmin($request, self::ONLY_ADMINS);

        return new StaffUserResource($this->member($user));
    }

    public function store(Request $request)
    {
        $this->requireAdmin($request, self::ONLY_ADMINS);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'role' => ['required', Rule::in(['admin', 'staff'])],
            'password' => ['nullable', PasswordRule::min(8)],
            'send_link' => ['nullable', 'boolean'],
            'department_ids' => ['nullable', 'array'],
            'department_ids.*' => ['integer', 'exists:ticket_departments,id'],
        ]);
        $user = $this->staff->create($data, $request->boolean('send_link'), ['via' => 'api']);
        if (array_key_exists('department_ids', $data)) {
            $user->departments()->sync($data['department_ids'] ?? []);
            $user->unsetRelation('departments');
        }

        return (new StaffUserResource($user))->response()->setStatusCode(201);
    }

    public function update(Request $request, User $user): StaffUserResource
    {
        $this->requireAdmin($request, self::ONLY_ADMINS);
        $this->member($user);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'role' => ['sometimes', Rule::in(['admin', 'staff'])],
            'department_ids' => ['nullable', 'array'],
            'department_ids.*' => ['integer', 'exists:ticket_departments,id'],
        ]);
        $updated = $this->staff->update($user, $data, ['via' => 'api']);
        if (array_key_exists('department_ids', $data)) { // support departments the member works in (replaces the list)
            $updated->departments()->sync($data['department_ids'] ?? []);
            $updated->unsetRelation('departments');
        }

        return new StaffUserResource($updated);
    }

    public function activate(Request $request, User $user): StaffUserResource
    {
        $this->requireAdmin($request, self::ONLY_ADMINS);

        return new StaffUserResource($this->staff->setActive($request->user(), $this->member($user), true, ['via' => 'api']));
    }

    /** Cannot target yourself or the last active administrator. Revokes the user's API tokens and sessions. */
    public function deactivate(Request $request, User $user): StaffUserResource
    {
        $this->requireAdmin($request, self::ONLY_ADMINS);

        return new StaffUserResource($this->staff->setActive($request->user(), $this->member($user), false, ['via' => 'api']));
    }

    public function sendResetLink(Request $request, User $user)
    {
        $this->requireAdmin($request, self::ONLY_ADMINS);
        $this->member($user);
        $this->staff->sendResetLink($user);
        $this->audit('staff.reset_link_sent', $user, "Sent a password reset link to {$user->email}");

        return response()->json(['data' => ['success' => true, 'message' => 'A reset link was sent to '.$user->email.'.']]);
    }

    /** Turn off the user's 2FA (lost phone). Their API tokens are kept unless revoke_api_tokens is true. Not available for yourself. */
    public function resetTwoFactor(Request $request, User $user): StaffUserResource
    {
        $this->requireAdmin($request, self::ONLY_ADMINS);
        $this->member($user);
        $data = $request->validate(['revoke_api_tokens' => ['nullable', 'boolean']]);
        abort_if($user->id === $request->user()->id, 403, 'Use your own security page to change your own two-factor settings.');
        $revoke = (bool) ($data['revoke_api_tokens'] ?? false);
        app(TwoFactor::class)->reset($user);
        $revoked = $revoke ? $user->apiTokens()->delete() : 0;
        $this->audit('two_factor.reset', $user, "{$request->user()->email} reset two-factor authentication for {$user->email}".($revoke ? " and revoked $revoked API token(s)" : ''), ['revoked_api_tokens' => $revoke ? $revoked : false]);

        return new StaffUserResource($user->refresh());
    }

    /** Only staff accounts belong to this resource; anything else is "not found". */
    private function member(User $user): User
    {
        abort_unless($user->isStaff(), 404, 'The requested resource was not found.');

        return $user;
    }
}
