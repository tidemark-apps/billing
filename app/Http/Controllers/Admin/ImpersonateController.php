<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Activity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ImpersonateController extends Controller
{
    public function start(Request $request, User $user): RedirectResponse
    {
        abort_if($user->isStaff() || ! $user->client_id || ! $user->is_active, 403, 'Only active client logins can be impersonated.');
        $staff = $request->user();
        Activity::log('impersonation.started', $user, "{$staff->email} viewed as {$user->email}");
        $request->session()->put('impersonator_id', $staff->id);
        Auth::login($user);
        $request->session()->put('impersonator_id', $staff->id);

        return redirect()->route('portal.dashboard');
    }

    public function stop(Request $request): RedirectResponse
    {
        $id = $request->session()->pull('impersonator_id');
        $staff = $id ? User::find($id) : null;
        abort_unless($staff && $staff->isStaff(), 403);
        Activity::log('impersonation.stopped', $request->user());
        Auth::login($staff);
        $request->session()->regenerate();

        return redirect()->route('admin.dashboard');
    }
}
