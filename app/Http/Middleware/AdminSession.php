<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs for every signed-in operator: ends a session left idle too long or signed out from another place
 * ("sign out everywhere" bumps the operator's session epoch), and keeps an operator who was given a
 * temporary password on the change-password page until they have set their own.
 */
class AdminSession
{
    public function handle(Request $request, Closure $next): Response
    {
        $admin = $request->user('admin');
        if (! $admin) {
            return $next($request);
        }

        $session = $request->session();
        $idleMinutes = \App\Support\PlatformSettings::effective('admin.idle_minutes');
        $last = $session->get('admin_last_activity');
        $epoch = $session->get('admin_epoch');

        $stale = ($idleMinutes > 0 && $last !== null && now()->timestamp - $last > $idleMinutes * 60)
            || ($epoch !== null && (int) $epoch !== (int) $admin->session_epoch)
            || $admin->is_active === false;

        if ($stale) {
            Auth::guard('admin')->logout();
            $session->invalidate();
            $session->regenerateToken();

            return redirect()->route('admin.login')->withErrors(['email' => 'You were signed out. Please sign in again.']);
        }

        $session->put('admin_last_activity', now()->timestamp);
        $session->put('admin_epoch', $admin->session_epoch);

        if ($admin->must_change_password && ! $request->routeIs('admin.password*', 'admin.logout')) {
            return redirect()->route('admin.password')->with('status', 'Choose your own password to continue.');
        }

        return $next($request);
    }
}
