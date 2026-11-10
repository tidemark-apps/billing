<?php

namespace App\Http\Middleware;

use App\Services\TicketService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Support tickets are optional: when switched off in Settings → Support, every ticket page and endpoint is a plain 404. */
class EnsureSupportEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(TicketService::enabled(), 404);

        return $next($request);
    }
}
