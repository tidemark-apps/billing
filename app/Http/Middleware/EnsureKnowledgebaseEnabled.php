<?php

namespace App\Http\Middleware;

use App\Services\Knowledgebase\Knowledgebase;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** The knowledge base is optional: when it is switched off in Settings, every page and endpoint is a plain 404. */
class EnsureKnowledgebaseEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(Knowledgebase::enabled(), 404);

        return $next($request);
    }
}
