<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\User;
use App\Support\Activity;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

/**
 * Staff (admin and staff role) accounts: create, change role, activate/deactivate. Shared by the admin screen and the
 * admin REST API so both enforce the same safeguards. Rule violations throw DomainException (a 422 in the API).
 *
 * Safeguards: the last active administrator can be neither demoted nor deactivated, and nobody can deactivate themselves.
 */
class StaffAccounts
{
    /** @param  array{name: string, email: string, role: string, password?: ?string}  $data  @param  array<string, mixed>  $meta  extra activity-log properties */
    public function create(array $data, bool $sendLink, array $meta = []): User
    {
        $password = $data['password'] ?? null;
        if (! $sendLink && ! $password) {
            throw new \DomainException('Set a password or choose to send a password link.');
        }
        $user = DB::transaction(function () use ($data, $password, $sendLink) {
            $user = User::create([
                'name' => $data['name'], 'email' => $data['email'], 'role' => Role::from($data['role']),
                'password' => $password ?: Str::random(40), 'is_active' => true,
            ]);
            if ($sendLink) {
                $this->sendResetLink($user);
            }

            return $user;
        });
        Activity::log('staff.created', $user, "Added {$user->role->value} user {$user->email}", $meta + ['role' => $user->role->value, 'link_sent' => $sendLink]);

        return $user;
    }

    /** @param  array{name?: string, email?: string, role?: string}  $data  @param  array<string, mixed>  $meta */
    public function update(User $user, array $data, array $meta = []): User
    {
        if (isset($data['role']) && Role::from($data['role']) !== $user->role && $user->isAdmin() && $this->isLastActiveAdmin($user)) {
            throw new \DomainException('This is the last active administrator, so it cannot be changed to staff. Make someone else an administrator first.');
        }
        $before = $user->role;
        $user->fill(array_intersect_key($data, array_flip(['name', 'email', 'role'])))->save();
        Activity::log('staff.updated', $user, "Updated staff user {$user->email}", $meta + ['fields' => array_keys($user->getChanges())]);
        if ($before !== $user->role) {
            Activity::log('staff.role_changed', $user, "Changed {$user->email} from {$before->value} to {$user->role->value}", $meta + ['from' => $before->value, 'to' => $user->role->value]);
        }

        return $user->refresh();
    }

    /** Deactivating signs the user out everywhere it can (database sessions) and revokes their API tokens. */
    public function setActive(User $actor, User $user, bool $active, array $meta = []): User
    {
        if (! $active) {
            if ($actor->id === $user->id) {
                throw new \DomainException('You cannot deactivate your own account.');
            }
            if ($user->isAdmin() && $this->isLastActiveAdmin($user)) {
                throw new \DomainException('This is the last active administrator and cannot be deactivated.');
            }
        }
        $user->forceFill(['is_active' => $active])->save();
        $revoked = 0;
        if (! $active) {
            $revoked = $user->apiTokens()->delete();
            $user->forceFill(['remember_token' => Str::random(60)])->saveQuietly();
            app(TwoFactor::class)->revokeAllDevices($user);
            if (config('session.driver') === 'database') {
                DB::connection(config('session.connection'))->table(config('session.table', 'sessions'))->where('user_id', $user->id)->delete();
            }
        }
        Activity::log($active ? 'staff.activated' : 'staff.deactivated', $user, ($active ? 'Activated' : 'Deactivated')." staff user {$user->email}".($active ? '' : " (revoked $revoked API token(s))"), $meta + ($active ? [] : ['revoked_api_tokens' => $revoked]));

        return $user->refresh();
    }

    /** Email the standard "choose a new password" link. */
    public function sendResetLink(User $user): void
    {
        $status = Password::sendResetLink(['email' => $user->email]);
        if ($status !== Password::RESET_LINK_SENT) {
            throw new \DomainException(__($status));
        }
    }

    public function isLastActiveAdmin(User $user): bool
    {
        return $user->isAdmin() && $user->is_active && ! User::where('role', Role::Admin)->where('is_active', true)->whereKeyNot($user->id)->exists();
    }
}
