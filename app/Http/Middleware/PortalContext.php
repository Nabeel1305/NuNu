<?php

namespace App\Http\Middleware;

use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs after auth:tenant. Pins every query in the request to the signed-in user's tenant, which
 * is what makes the tenant-scoped models safe to use directly in portal controllers. A user whose
 * access was switched off since they signed in is signed out on their next request.
 */
class PortalContext
{
    public function __construct(private readonly TenantContext $context)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user('tenant');

        if (! $user || ! $user->is_active) {
            Auth::guard('tenant')->logout();
            $request->session()->invalidate();

            return redirect()->route('portal.login')->withErrors(['email' => 'Your access has been switched off. Contact your account owner.']);
        }

        $tenant = \App\Models\Tenant::find($user->tenant_id);
        abort_if($tenant === null, 403);

        $this->context->set($tenant);
        $request->attributes->set('tenant', $tenant);
        view()->share('tenant', $tenant);

        try {
            return $next($request);
        } finally {
            $this->context->clear();
        }
    }
}
