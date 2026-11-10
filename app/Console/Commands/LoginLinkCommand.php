<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\URL;

/** A one-time sign-in link for a user, for handing a new install to its owner or for support. Shell access proves authority. */
class LoginLinkCommand extends Command
{
    protected $signature = 'billing:login-link {email : Email address of the user} {--minutes=60 : How long the link works}';

    protected $description = 'Print a one-time sign-in link for a user (works once; two-factor still applies)';

    public function handle(): int
    {
        $user = User::where('email', $this->argument('email'))->where('is_active', true)->first();
        if (! $user) {
            $this->error('No active user with that email address.');

            return self::FAILURE;
        }
        $minutes = max(1, min(1440, (int) $this->option('minutes')));
        $this->line(URL::temporarySignedRoute('login.link', now()->addMinutes($minutes), ['user' => $user->id]));

        return self::SUCCESS;
    }
}
