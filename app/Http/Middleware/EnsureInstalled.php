<?php

namespace App\Http\Middleware;

use App\Support\Installer;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Until the installer has run, every page redirects to /install (and /install is closed afterwards). */
class EnsureInstalled
{
    public function handle(Request $request, Closure $next): Response
    {
        $installed = Installer::isInstalled();
        $onInstaller = $request->is('install', 'install/*');

        if (! $installed && ! $onInstaller && ! $request->is('up')) {
            abort_if(config('billing.hosted'), 503, 'This install is still being set up. Try again in a minute.');

            return redirect('/install');
        }
        if ($onInstaller && config('billing.hosted')) {
            abort(404); // a hosted install is created with `php artisan billing:install`, never through the browser
        }
        if ($installed && $onInstaller) {
            return redirect('/');
        }

        return $next($request);
    }
}
