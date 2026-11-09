<?php

use Illuminate\Support\Facades\Schedule;

// Add ONE cron entry:  * * * * * php /path/to/artisan schedule:run >> /dev/null 2>&1
// On hosts without a command-line cron, call /cron/{BILLING_CRON_TOKEN} every minute instead.

Schedule::command('billing:run')->dailyAt('02:30')->withoutOverlapping();

// Charge saved payment methods for invoices that are due and retry failed automatic payments.
Schedule::command('billing:autopay')->hourly()->withoutOverlapping();

// Process queued jobs (webhook deliveries, provisioning) when no dedicated worker is running.
if (config('queue.default') !== 'sync' && ! env('BILLING_EXTERNAL_WORKER')) {
    Schedule::command('queue:work --stop-when-empty --max-time=50 --tries=3')->everyMinute()->withoutOverlapping();
}
