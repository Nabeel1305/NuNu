<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Same rule as for operators: where two-factor is required, an account without it can only reach the setup page. */
class EnsureTenantTwoFactor
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user('tenant');

        // An operator viewing as this person has passed their own two-factor check; the person's setup is not theirs to do.
        if ($user && ! \App\Support\Impersonation::current($request) && EnsureTwoFactor::required() && ! $user->hasTwoFactor()) {
            return redirect()->route('portal.account')->with('status', 'Set up two-factor authentication to continue.');
        }

        return $next($request);
    }
}
