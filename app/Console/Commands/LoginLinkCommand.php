<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;

/** A one-time sign-in link for a user, for handing a new install to its owner or for support. Shell access proves authority. */
class LoginLinkCommand extends Command
{
    protected $signature = 'billing:login-link {email : Email address of the user} {--minutes=60 : How long the link works} {--reset : Print a link that lets the user choose a password (valid for PASSWORD_RESET_EXPIRE minutes) instead of signing in}';

    protected $description = 'Print a one-time sign-in link for a user (works once; two-factor still applies), or with --reset a link to set a password';

    public function handle(): int
    {
        $user = User::where('email', $this->argument('email'))->where('is_active', true)->first();
        if (! $user) {
            $this->error('No active user with that email address.');

            return self::FAILURE;
        }
        if ($this->option('reset')) {
            // For a new owner: the sign-in link above cannot set a password, because changing it asks for the current one.
            $this->line(route('password.reset', ['token' => Password::broker()->createToken($user), 'email' => $user->email]));

            return self::SUCCESS;
        }
        $minutes = max(1, min(1440, (int) $this->option('minutes')));
        $this->line(URL::temporarySignedRoute('login.link', now()->addMinutes($minutes), ['user' => $user->id]));

        return self::SUCCESS;
    }
}
