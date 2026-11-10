<?php

namespace App\Http\Middleware;

use App\Services\Knowledgebase\Knowledgebase;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** The reader side: 404 while the knowledge base is off; guests are sent to sign in when it is not open to them. */
class EnsureKnowledgebaseReadable
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(Knowledgebase::enabled(), 404);
        if (! Knowledgebase::publicAccess() && ! $request->user()) {
            return redirect()->guest(route('login'));
        }

        return $next($request);
    }
}
