<?php

use App\Services\Settlement\SettlementRejected;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Trust no proxy unless told to: set TRUSTED_PROXIES to '*' or a comma-separated list
        // of addresses when behind a load balancer, or https links and client IPs come out wrong.
        $trusted = env('TRUSTED_PROXIES');
        if ($trusted) {
            $middleware->trustProxies(at: $trusted === '*' ? '*' : array_map('trim', explode(',', $trusted)));
        }

        $middleware->append(\App\Http\Middleware\SecurityHeaders::class);

        // Unauthenticated visitors go to the login of the area they asked for; the API never redirects.
        $middleware->redirectGuestsTo(fn ($request) => $request->is('portal', 'portal/*') ? route('portal.login') : route('admin.login'));

        $middleware->alias([
            'portal.context' => \App\Http\Middleware\PortalContext::class,
            'portal.2fa' => \App\Http\Middleware\EnsureTenantTwoFactor::class,
            'portal.can' => \App\Http\Middleware\PortalAbility::class,
        ]);

        // The API key middleware must run before the throttle, which keys on the tenant it finds.
        $middleware->prependToPriorityList(
            before: \Illuminate\Routing\Middleware\ThrottleRequests::class,
            prepend: \App\Http\Middleware\AuthenticateTenant::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->shouldRenderJsonWhen(fn ($request) => $request->is('api/*'));

        $exceptions->render(function (NotFoundHttpException $e, $request) {
            if ($request->is('api/*')) {
                return response()->json(['error' => ['code' => 'not_found', 'message' => 'Not found.']], 404);
            }
        });

        $exceptions->render(function (SettlementRejected $e, $request) {
            return response()->json(['error' => ['code' => 'settlement_rejected', 'message' => $e->getMessage()]], 422);
        });
    })->create();
