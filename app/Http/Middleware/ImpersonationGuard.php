<?php

namespace App\Http\Middleware;

use App\Services\Audit\AuditLogService;
use App\Support\Impersonation;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs inside the portal when an operator is viewing it as a tenant's user. The session is
 * look-only and short: it ends by itself after Impersonation::MINUTES, the moment the operator who
 * started it is no longer signed in (or is switched off), and it never lets the operator change
 * anything or open the pages that show the person's own security details.
 */
class ImpersonationGuard
{
    /** Pages that would reveal or change the person's own credentials. */
    private const FORBIDDEN_ROUTES = ['portal.account', 'portal.account.*'];

    public function __construct(private readonly AuditLogService $audit)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $imp = Impersonation::current($request);
        if ($imp === null) {
            return $next($request);
        }

        $admin = Auth::guard('admin')->user();
        $stillValid = $admin && $admin->is_active !== false && (int) $admin->id === (int) $imp['admin_id']
            && (int) $request->user('tenant')?->id === (int) $imp['user_id'];

        if (! $stillValid) {
            Impersonation::end($request, $this->audit, 'the operator is no longer signed in');

            return redirect()->route('portal.login');
        }

        if (now()->timestamp >= $imp['expires_at']) {
            Impersonation::end($request, $this->audit, 'time limit reached');

            return redirect()->route('admin.tenants.show', $imp['tenant_id'])->with('status', 'The view-as session ended (time limit).');
        }

        if (! $request->isMethodSafe() && ! $request->routeIs('portal.impersonation.stop')) {
            abort(403, 'You are viewing this portal as someone else. It is look-only.');
        }
        abort_if($request->routeIs(...self::FORBIDDEN_ROUTES), 403, 'This page is not available while viewing as someone else.');

        view()->share('impersonation', $imp);

        return $next($request);
    }
}
