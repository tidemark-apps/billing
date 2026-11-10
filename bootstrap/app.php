<?php

use App\Http\Middleware\AuthenticateApi;
use App\Http\Middleware\EnsureActive;
use App\Http\Middleware\EnsureCustomer;
use App\Http\Middleware\EnsureInstalled;
use App\Http\Middleware\EnsureStaff;
use App\Http\Middleware\EnsureSupportEnabled;
use App\Http\Middleware\RequireAbility;
use App\Support\Environment;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

// First run on shared hosting: create .env (with an application key) so the installer can start.
Environment::ensureFile(dirname(__DIR__));

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        apiPrefix: 'api/v1',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [EnsureInstalled::class, EnsureActive::class]);
        $middleware->alias([
            'staff' => EnsureStaff::class,
            'customer' => EnsureCustomer::class,
            'auth.api' => AuthenticateApi::class,
            'ability' => RequireAbility::class,
            'support' => EnsureSupportEnabled::class,
        ]);
        // A password may legitimately start or end with a space.
        $middleware->trimStrings(except: ['imap_password']);
        $middleware->validateCsrfTokens(except: ['webhooks/*']);
        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->trustProxies(at: env('TRUSTED_PROXIES', '*') === '*' ? '*' : explode(',', (string) env('TRUSTED_PROXIES')));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $request->is('api/*') || $request->expectsJson());
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }
            $headers = [];
            $details = null;
            if ($e instanceof ValidationException) {
                [$status, $code, $message, $details] = [422, 'validation_failed', 'The given data was invalid.', $e->errors()];
            } elseif ($e instanceof DomainException) {
                [$status, $code, $message] = [422, 'domain_error', $e->getMessage()];
            } elseif ($e instanceof AuthenticationException) {
                [$status, $code, $message] = [401, 'unauthenticated', 'Missing or invalid API token.'];
            } elseif ($e instanceof AuthorizationException) {
                [$status, $code, $message] = [403, 'forbidden', $e->getMessage() ?: 'This action is forbidden.'];
            } elseif ($e instanceof ModelNotFoundException || $e instanceof NotFoundHttpException) {
                [$status, $code, $message] = [404, 'not_found', 'The requested resource was not found.'];
            } elseif ($e instanceof TooManyRequestsHttpException) {
                [$status, $code, $message] = [429, 'too_many_requests', 'Too many requests. Slow down and retry.'];
                $headers = $e->getHeaders();
            } elseif ($e instanceof HttpExceptionInterface) {
                $status = $e->getStatusCode();
                $code = match ($status) {
                    403 => 'forbidden', 405 => 'method_not_allowed', 419 => 'expired', default => 'http_error',
                };
                $message = $e->getMessage() ?: (Response::$statusTexts[$status] ?? 'Error');
                $headers = $e->getHeaders();
            } else {
                report($e);
                [$status, $code, $message] = [500, 'server_error', config('app.debug') ? $e->getMessage() : 'Something went wrong on our side.'];
            }
            $error = ['code' => $code, 'message' => $message] + ($details ? ['details' => $details] : []);

            return response()->json(['error' => $error], $status, $headers);
        });
    })->create();
