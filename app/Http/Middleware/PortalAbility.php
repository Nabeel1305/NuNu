<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Usage: ->middleware('portal.can:money'). Anything the role does not allow is a 403. */
class PortalAbility
{
    public function handle(Request $request, Closure $next, string $ability): Response
    {
        abort_unless($request->user('tenant')?->can($ability), 403, 'Your role does not allow this.');

        return $next($request);
    }
}
