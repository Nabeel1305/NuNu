<?php

namespace App\Http\Middleware;

use App\Support\PlatformAudit;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Usage: ->middleware('admin.can:operators'). An operator whose role does not allow it gets a 403, and the attempt is logged. */
class AdminAbility
{
    public function handle(Request $request, Closure $next, string $ability): Response
    {
        $admin = $request->user('admin');

        if (! $admin?->can($ability)) {
            PlatformAudit::record($admin, 'admin.denied', "Not allowed: {$request->method()} /{$request->path()} needs '{$ability}'", null, null, [], 'denied');
            abort(403, 'Your role does not allow this.');
        }

        return $next($request);
    }
}
