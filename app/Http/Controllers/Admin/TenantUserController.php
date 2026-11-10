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

    /**
     * Look at the portal as this person sees it, to help with a support request. Look-only, 30 minutes at most, needs a
     * reason, and is written to the tenant's own audit log.
     */
    public function impersonate(Request $request, Tenant $tenant, int $user): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:300']]);
        $target = $this->find($tenant, $user);

        if (! $target->is_active || ! $target->hasAccepted()) {
            return back()->withErrors(['reason' => 'That person is switched off or has not accepted their invitation yet.']);
        }

        \App\Support\Impersonation::start($request, $this->audit, $request->user('admin'), $tenant, $target, $data['reason']);

        return redirect()->route('portal.dashboard');
    }

    public function role(Request $request, Tenant $tenant, int $user): RedirectResponse
    {
        $data = $request->validate(['role' => ['required', Rule::in(TenantUser::ROLES)]]);
        $target = $this->find($tenant, $user);
        $this->guardLastOwner($tenant, $target, $data['role'] === 'owner');
        $old = $target->role;
        $target->update($data);
        $this->record($request, $tenant, 'admin.portal_user_role', "Portal user {$target->email} changed from {$old} to {$data['role']}", $target);

        return back()->with('status', 'Role changed.');
    }

    public function destroy(Request $request, Tenant $tenant, int $user): RedirectResponse
    {
        $target = $this->find($tenant, $user);
        $this->guardLastOwner($tenant, $target, false);
        $this->record($request, $tenant, 'admin.portal_user_removed', "Portal user {$target->email} removed", $target);
        \App\Models\TenantInvitation::withoutGlobalScopes()->where('tenant_user_id', $target->id)->delete();
        $target->delete();

        return back()->with('status', 'Removed.');
    }

    /** A tenant with a portal keeps at least one active owner. */
    private function guardLastOwner(Tenant $tenant, TenantUser $target, bool $staysOwner): void
    {
        if ($target->role === 'owner' && $target->is_active && ! $staysOwner) {
            $others = TenantUser::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('role', 'owner')->where('is_active', true)->where('id', '!=', $target->id)->count();
            abort_if($others === 0, 422, 'The tenant must keep at least one active owner.');
        }
    }

    public function toggle(Request $request, Tenant $tenant, int $user): RedirectResponse
    {
        $target = $this->find($tenant, $user);
        $target->update(['is_active' => ! $target->is_active]);
        if (! $target->is_active) {
            \App\Models\TenantInvitation::withoutGlobalScopes()->where('tenant_user_id', $target->id)->whereNull('accepted_at')->delete();
        }
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
