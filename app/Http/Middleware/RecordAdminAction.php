<?php

namespace App\Http\Middleware;

use App\Support\PlatformAudit;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every change an operator makes (any non-read request) is written to the platform audit log with who, what,
 * which tenant, from where and whether it went through. Passwords and codes are never recorded.
 */
class RecordAdminAction
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $admin = $request->user('admin');
        if ($admin && ! $request->isMethodSafe()) {
            $route = $request->route();
            $tenant = $route?->parameter('tenant');
            $tenantId = $tenant instanceof \App\Models\Tenant ? $tenant->id : (is_numeric($tenant) ? (int) $tenant : null);
            $tenantId ??= $request->attributes->get('audit_tenant_id');   // set by actions addressed by payment, phone or delivery
            $ok = $response->getStatusCode() < 400 && ! ($response instanceof \Illuminate\Http\RedirectResponse && $request->session()->has('errors'));

            PlatformAudit::record($admin, $route?->getName() ?? $request->path(), "{$request->method()} /{$request->path()}", $tenantId, null,
                PlatformAudit::inputSummary($request), $ok ? 'ok' : 'failed');
        }

        return $response;
    }
}
