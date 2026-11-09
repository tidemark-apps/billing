<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\ManagesTwoFactor;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\TwoFactor;
use App\Support\Activity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AccountSecurityController extends Controller
{
    use ManagesTwoFactor;

    protected string $routePrefix = 'admin.account.security';

    public function index(Request $request)
    {
        return view('admin.account.security', $this->panel($request));
    }

    /**
     * Admin tool: wipe a login's 2FA (lost phone and recovery codes). Staff may reset clients; only admins reset staff.
     * Existing API tokens keep working unless "revoke_tokens" is sent (tick it when the account may be compromised).
     */
    public function reset(Request $request, User $user): RedirectResponse
    {
        $actor = $request->user();
        abort_if($user->isStaff() && ! $actor->isAdmin(), 403, 'Only administrators can reset staff two-factor authentication.');
        abort_if($user->id === $actor->id, 403, 'Use your own security page to change your own two-factor settings.');
        $revoke = $request->boolean('revoke_tokens');
        app(TwoFactor::class)->reset($user);
        $revoked = $revoke ? $user->apiTokens()->delete() : 0;
        Activity::log('two_factor.reset', $user, "{$actor->email} reset two-factor authentication for {$user->email}".($revoke ? " and revoked $revoked API token(s)" : ''), ['revoked_api_tokens' => $revoke ? $revoked : false]);

        return back()->with('status', "Two-factor authentication reset for {$user->email}".($revoke ? " and $revoked API ".Str::plural('token', $revoked).' revoked' : '').'.');
    }
}
