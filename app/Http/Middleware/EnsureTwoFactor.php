<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * When a second factor is required (always in production unless switched off with
 * PLATFORM_ADMIN_REQUIRE_2FA=false), an admin without one can only reach the page
 * that sets it up.
 */
class EnsureTwoFactor
{
    public static function required(): bool
    {
        return (bool) (config('platform.admin.require_two_factor') ?? app()->environment('production'));
    }

    public function handle(Request $request, Closure $next): Response
    {
        $admin = $request->user('admin');

        if ($admin && self::required() && ! $admin->hasTwoFactor()) {
            return redirect()->route('admin.security')->with('status', 'Set up two-factor authentication to continue.');
        }

        return $next($request);
    }
}
