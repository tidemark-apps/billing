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
            return redirect('/install');
        }
        if ($installed && $onInstaller) {
            return redirect('/');
        }

        return $next($request);
    }
}
