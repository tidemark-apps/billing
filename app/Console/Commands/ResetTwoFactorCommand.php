<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\TwoFactor;
use App\Support\Activity;
use Illuminate\Console\Command;

/** Recovery for a locked-out administrator: shell access proves ownership of the server. */
class ResetTwoFactorCommand extends Command
{
    protected $signature = 'billing:reset-2fa {email : Email address of the user} {--revoke-tokens : Also revoke the user\'s API tokens}';

    protected $description = 'Turn off two-factor authentication for a user (lost phone and recovery codes)';

    public function handle(TwoFactor $twoFactor): int
    {
        $user = User::where('email', $this->argument('email'))->first();
        if (! $user) {
            $this->error('No user with that email address.');

            return self::FAILURE;
        }
        $twoFactor->reset($user);
        $revoke = (bool) $this->option('revoke-tokens');
        $revoked = $revoke ? $user->apiTokens()->delete() : 0;
        Activity::log('two_factor.reset', $user, "Two-factor authentication reset for {$user->email} from the command line".($revoke ? " and $revoked API token(s) revoked" : ''), ['revoked_api_tokens' => $revoke ? $revoked : false]);
        $this->info("Two-factor authentication is off for {$user->email}. They can sign in with their password and set it up again.");

        return self::SUCCESS;
    }
}
