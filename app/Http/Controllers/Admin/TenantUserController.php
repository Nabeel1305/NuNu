<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\TenantInvitation;
use App\Models\TenantUser;
use App\Services\Audit\AuditLogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Operator side of the portal: give a tenant its first owner, and recover access when an owner is locked out. */
class TenantUserController extends Controller
{
    public function __construct(private readonly AuditLogService $audit)
    {
    }

    public function store(Request $request, Tenant $tenant): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:191', 'unique:tenant_users,email', 'unique:admins,email'],
            'role' => ['required', Rule::in(TenantUser::ROLES)],
        ]);

        $user = TenantUser::withoutGlobalScopes()->create($data + ['tenant_id' => $tenant->id, 'is_active' => true]);
        $plain = TenantInvitation::issue($user);
        $this->record($request, $tenant, 'admin.portal_user_invited', "Portal user {$user->email} invited as {$user->role}", $user);

        return back()->with('secret', ['label' => "Portal invitation for {$user->email} (valid 7 days)", 'value' => route('portal.invitation', $plain)]);
    }

    public function reinvite(Request $request, Tenant $tenant, int $user): RedirectResponse
    {
        $target = $this->find($tenant, $user);
        $plain = TenantInvitation::issue($target);
        $this->record($request, $tenant, 'admin.portal_user_reinvited', "New portal access link issued for {$target->email}", $target);

        return back()->with('secret', ['label' => "Portal access link for {$target->email} (valid 7 days)", 'value' => route('portal.invitation', $plain)]);
    }

    public function resetTwoFactor(Request $request, Tenant $tenant, int $user): RedirectResponse
    {
        $target = $this->find($tenant, $user);
        $target->forceFill(['totp_secret' => null, 'totp_confirmed_at' => null, 'totp_last_step' => null])->save();
        $this->record($request, $tenant, 'admin.portal_user_2fa_reset', "Two-factor reset for portal user {$target->email}", $target);

        return back()->with('status', 'Two-factor reset.');
    }

    public function toggle(Request $request, Tenant $tenant, int $user): RedirectResponse
    {
        $target = $this->find($tenant, $user);
        $target->update(['is_active' => ! $target->is_active]);
        $this->record($request, $tenant, 'admin.portal_user_toggled', "Portal user {$target->email} " . ($target->is_active ? 'enabled' : 'switched off'), $target);

        return back()->with('status', 'Updated.');
    }

    private function find(Tenant $tenant, int $id): TenantUser
    {
        return TenantUser::withoutGlobalScopes()->where('tenant_id', $tenant->id)->findOrFail($id);
    }

    private function record(Request $request, Tenant $tenant, string $event, string $description, TenantUser $subject): void
    {
        $this->audit->record($tenant->id, $event, 'admin', $request->user('admin')->id, TenantUser::class, $subject->id, $description);
    }
}
