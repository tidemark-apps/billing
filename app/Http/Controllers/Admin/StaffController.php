<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\User;
use App\Services\StaffAccounts;
use App\Support\Activity;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password as PasswordRule;

/**
 * Staff management (administrators only, enforced by the route group): list, invite, change role, activate/deactivate,
 * send a password link. Two-factor reset reuses admin.users.two-factor.reset (AccountSecurityController::reset).
 */
class StaffController extends Controller
{
    public function __construct(private readonly StaffAccounts $staff) {}

    public function index(Request $request)
    {
        $all = User::whereIn('role', [Role::Admin, Role::Staff])->orderByDesc('is_active')->orderBy('name')->with('departments')->get();
        $lastAdmin = $all->first(fn (User $u) => $this->staff->isLastActiveAdmin($u));
        $counts = [
            'all' => $all->count(),
            'active' => $all->where('is_active', true)->count(),
            'inactive' => $all->where('is_active', false)->count(),
            'admins' => $all->filter(fn (User $u) => $u->isAdmin())->count(),
            'no2fa' => $all->filter(fn (User $u) => $u->is_active && ! $u->hasTwoFactor())->count(),
        ];
        $view = in_array($request->query('view'), ['active', 'inactive', 'admins', 'no2fa'], true) ? $request->query('view') : 'all';
        $q = trim((string) $request->query('q', ''));
        $users = $all->filter(fn (User $u) => match ($view) {
            'active' => $u->is_active,
            'inactive' => ! $u->is_active,
            'admins' => $u->isAdmin(),
            'no2fa' => $u->is_active && ! $u->hasTwoFactor(),
            default => true,
        })->when($q !== '', fn ($c) => $c->filter(fn (User $u) => str_contains(mb_strtolower($u->name.' '.$u->email), mb_strtolower($q))))->values();

        return view('admin.staff.index', ['users' => $users, 'lastAdminId' => $lastAdmin?->id, 'counts' => $counts, 'view' => $view, 'q' => $q, 'twoFactorRequired' => (bool) Settings::bool('security.require_staff_2fa')]);
    }

    public function create()
    {
        return view('admin.staff.form', ['staff' => new User(['role' => Role::Staff]), 'lastAdmin' => false, 'departments' => Department::orderBy('sort')->orderBy('name')->get(), 'selected' => []]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'role' => ['required', Rule::in(['admin', 'staff'])],
            'password_mode' => ['required', Rule::in(['set', 'link'])],
            'password' => ['required_if:password_mode,set', 'nullable', PasswordRule::min(8)],
            'departments' => ['nullable', 'array'],
            'departments.*' => ['integer', 'exists:ticket_departments,id'],
        ]);
        try {
            $user = $this->staff->create(Arr::except($data, ['departments']), $data['password_mode'] === 'link');
            $user->departments()->sync($data['departments'] ?? []);
        } catch (\DomainException $e) {
            return back()->withInput($request->except('password'))->with('error', $e->getMessage());
        }

        return redirect()->route('admin.staff.index')->with('status', "Added {$user->email}".($data['password_mode'] === 'link' ? ' and sent a link to choose a password.' : '.'));
    }

    public function edit(User $user)
    {
        $this->member($user);

        return view('admin.staff.form', ['staff' => $user, 'lastAdmin' => $this->staff->isLastActiveAdmin($user), 'departments' => Department::orderBy('sort')->orderBy('name')->get(), 'selected' => $user->departments()->pluck('ticket_departments.id')->all()]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $this->member($user);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'role' => ['required', Rule::in(['admin', 'staff'])],
            'departments' => ['nullable', 'array'],
            'departments.*' => ['integer', 'exists:ticket_departments,id'],
        ]);
        try {
            $this->staff->update($user, Arr::except($data, ['departments']));
            $user->departments()->sync($data['departments'] ?? []);
        } catch (\DomainException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('admin.staff.index')->with('status', "Saved {$user->email}.");
    }

    public function activate(Request $request, User $user): RedirectResponse
    {
        $this->member($user);
        $this->staff->setActive($request->user(), $user, true);

        return back()->with('status', "{$user->email} is active again.");
    }

    public function deactivate(Request $request, User $user): RedirectResponse
    {
        $this->member($user);
        try {
            $this->staff->setActive($request->user(), $user, false);
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', "{$user->email} was deactivated; their API tokens were revoked and sessions ended where possible.");
    }

    public function sendResetLink(User $user): RedirectResponse
    {
        $this->member($user);
        try {
            $this->staff->sendResetLink($user);
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
        Activity::log('staff.reset_link_sent', $user, "Sent a password reset link to {$user->email}");

        return back()->with('status', "A password reset link was sent to {$user->email}.");
    }

    /** Only staff accounts belong here; a client login is "not found". */
    private function member(User $user): void
    {
        abort_unless($user->isStaff(), 404);
    }
}
