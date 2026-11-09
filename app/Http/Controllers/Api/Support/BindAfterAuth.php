<?php

namespace App\Http\Controllers\Api\Support;

use Closure;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route-model binding that runs after token authentication.
 *
 * The framework's SubstituteBindings sits in the "api" middleware group, so it runs before `auth.api`: an unauthenticated
 * request or a customer token asking for /admin/clients/999 would get 404 for a missing record and 401/403 for an existing
 * one. The admin group removes the stock middleware and adds this one at the end of its list so that authentication and
 * the audience check always answer first. (It is deliberately not a subclass: `withoutMiddleware()` also excludes subclasses.)
 */
class BindAfterAuth
{
    public function __construct(private readonly Router $router) {}

    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route();
        try {
            $this->router->substituteBindings($route);
            $this->router->substituteImplicitBindings($route);
        } catch (ModelNotFoundException $exception) {
            if ($route->getMissing()) {
                return $route->getMissing()($request, $exception);
            }

            throw $exception;
        }

        return $next($request);
    }
}
