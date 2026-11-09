<?php

namespace App\Http\Controllers;

use App\Support\Settings;
use Illuminate\Support\Facades\Artisan;

/** For hosts without a command-line cron: GET /cron/{token} every minute runs the scheduler. */
class CronController extends Controller
{
    public function __invoke(string $token)
    {
        $expected = (string) (config('billing.cron_token') ?: Settings::get('system.cron_token'));
        abort_unless($expected !== '' && hash_equals($expected, $token), 404);

        Artisan::call('schedule:run');

        return response('ok', 200, ['Content-Type' => 'text/plain']);
    }
}
