<?php

namespace App\Providers;

use App\Events\InvoiceCreated;
use App\Events\InvoicePaid;
use App\Listeners\AutoPayInvoice;
use App\Listeners\FulfillPaidInvoice;
use App\Listeners\QueueWebhooks;
use App\Services\Knowledgebase\Knowledgebase;
use App\Support\Plan;
use App\Support\Settings;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Http\Request;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\Mime\Address;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // one tree per request, shared by the controllers and the views
        $this->app->scoped(Knowledgebase::class);
    }

    public function boot(): void
    {
        Paginator::defaultView('vendor.pagination.tidemark');

        // Case-insensitive "contains" search that treats % and _ in the term literally on every database.
        // (Plain `like` with a backslash only escapes on MySQL; SQLite and PostgreSQL need an explicit ESCAPE.)
        foreach (['whereContains' => 'and', 'orWhereContains' => 'or'] as $name => $boolean) {
            EloquentBuilder::macro($name, function (string $column, string $term) use ($boolean) {
                /** @var EloquentBuilder $this */
                $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term).'%';

                return $this->whereRaw($this->getQuery()->getGrammar()->wrap($column)." like ? escape '!'", [$like], $boolean);
            });
        }

        Event::listen(InvoicePaid::class, FulfillPaidInvoice::class);
        Event::listen(InvoiceCreated::class, AutoPayInvoice::class);
        Event::listen('App\Events\*', QueueWebhooks::class);

        RateLimiter::for('api', fn (Request $r) => Limit::perMinute(120)->by($r->attributes->get('api_token')?->id ?: $r->ip()));
        RateLimiter::for('api-auth', fn (Request $r) => Limit::perMinute(10)->by($r->ip()));

        $this->applyMailSettings();
        $this->hostedMail();
    }

    /** Hosted installs send through the host's shared relay: replies go to the owner, and the plan's daily allowance applies. */
    private function hostedMail(): void
    {
        Event::listen(MessageSending::class, function (MessageSending $event) {
            if (! config('billing.hosted')) {
                return null;
            }
            $message = $event->message;
            if (! $message->getReplyTo() && ($reply = Settings::get('company.email'))) {
                $message->replyTo(new Address($reply, (string) Settings::get('company.name', config('billing.name'))));
            }
            if (! Plan::allowEmail()) {
                Log::warning('Email not sent: the plan\'s daily email allowance is used up.', ['subject' => $message->getSubject()]);

                return false;
            }

            return null;
        });
    }

    /** Let admins configure outgoing email in Settings → Email instead of editing .env on shared hosting. */
    private function applyMailSettings(): void
    {
        if (config('billing.hosted')) {
            // The transport and sender address are fixed by the host; the owner can only choose the display name (default: the business name).
            if ($name = Settings::get('mail.from_name') ?: Settings::get('company.name')) {
                config(['mail.from.name' => $name]);
            }

            return;
        }
        $mailer = Settings::get('mail.mailer');
        if (! $mailer) {
            return;
        }
        config(['mail.default' => $mailer]);
        if ($mailer === 'smtp') {
            config([
                'mail.mailers.smtp.host' => Settings::get('mail.host'),
                'mail.mailers.smtp.port' => (int) Settings::get('mail.port', 587),
                'mail.mailers.smtp.username' => Settings::get('mail.username'),
                'mail.mailers.smtp.password' => Settings::get('mail.password'),
                'mail.mailers.smtp.scheme' => Settings::get('mail.encryption') === 'ssl' ? 'smtps' : 'smtp',
            ]);
        }
        if ($from = Settings::get('mail.from_address')) {
            config(['mail.from.address' => $from, 'mail.from.name' => Settings::get('mail.from_name') ?: Settings::get('company.name', config('billing.name'))]);
        }
    }
}
