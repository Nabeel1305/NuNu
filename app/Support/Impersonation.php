<?php

namespace App\Support;

use App\Models\Admin;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Services\Audit\AuditLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * An operator looking at a tenant's portal as one of its users, to help with a support request.
 * The state lives in the operator's own session; starting and ending are both written to the tenant's
 * audit log, so the tenant can see who looked, when, and why.
 */
final class Impersonation
{
    public const SESSION_KEY = 'impersonation';
    public const MINUTES = 30;

    /** @return array{admin_id:int, admin_email:string, user_id:int, tenant_id:int, started_at:int, expires_at:int, reason:string}|null */
    public static function current(Request $request): ?array
    {
        $imp = $request->session()->get(self::SESSION_KEY);

        return is_array($imp) && isset($imp['admin_id'], $imp['user_id'], $imp['tenant_id'], $imp['expires_at']) ? $imp : null;
    }

    public static function start(Request $request, AuditLogService $audit, Admin $admin, Tenant $tenant, TenantUser $user, string $reason): void
    {
        Auth::guard('tenant')->login($user);
        $request->session()->regenerate();   // a fresh session id for the new identity

        $request->session()->put(self::SESSION_KEY, [
            'admin_id' => $admin->id, 'admin_email' => $admin->email, 'user_id' => $user->id, 'tenant_id' => $tenant->id,
            'started_at' => now()->timestamp, 'expires_at' => now()->addMinutes(self::MINUTES)->timestamp, 'reason' => $reason,
        ]);

        $audit->record($tenant->id, 'admin.impersonation_started', 'admin', $admin->id, TenantUser::class, $user->id,
            "Operator {$admin->email} started viewing the portal as {$user->email}",
            ['reason' => $reason, 'ip' => $request->ip(), 'expires_in_minutes' => self::MINUTES]);
    }

    /** Ends the view-as session and signs the borrowed identity out, leaving the operator's own session alone. */
    public static function end(Request $request, AuditLogService $audit, string $why): void
    {
        $imp = self::current($request);
        Auth::guard('tenant')->logout();
        $request->session()->forget(self::SESSION_KEY);
        $request->session()->regenerateToken();

        if ($imp !== null) {
            $audit->record($imp['tenant_id'], 'admin.impersonation_ended', 'admin', $imp['admin_id'], TenantUser::class, $imp['user_id'],
                "Operator {$imp['admin_email']} stopped viewing the portal as someone else ({$why})",
                ['seconds' => max(0, now()->timestamp - $imp['started_at']), 'why' => $why]);
        }
    }
}
