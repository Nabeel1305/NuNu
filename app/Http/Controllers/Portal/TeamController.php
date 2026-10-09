<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\TenantInvitation;
use App\Models\TenantUser;
use App\Services\Audit\AuditLogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** Owner-only: who can sign in to this tenant's portal, and with which role. */
class TeamController extends Controller
{
    public function __construct(private readonly AuditLogService $audit)
    {
    }

    public function index(Request $request): View
    {
        $open = TenantInvitation::whereNull('accepted_at')->where('expires_at', '>', now())->pluck('tenant_user_id')->all();

        return view('portal.team', [
            'users' => TenantUser::orderByRaw("case role when 'owner' then 0 when 'developer' then 1 else 2 end")->orderBy('name')->get(),
            'invited' => array_flip($open),
            'roles' => TenantUser::ROLES,
            'me' => $request->user('tenant'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:191', 'unique:tenant_users,email', 'unique:admins,email'],
            'role' => ['required', Rule::in(TenantUser::ROLES)],
        ]);

        $user = TenantUser::create($data + ['is_active' => true]);
        $plain = TenantInvitation::issue($user);
        $this->record($request, 'portal.user_invited', "invited {$user->email} as {$user->role}", $user);

        return back()->with('secret', ['label' => "Invitation link for {$user->email} (valid 7 days)", 'value' => route('portal.invitation', $plain)]);
    }

    public function update(Request $request, int $user): RedirectResponse
    {
        $target = TenantUser::findOrFail($user);
        $data = $request->validate(['role' => ['required', Rule::in(TenantUser::ROLES)]]);

        $this->guardSelf($request, $target);
        if ($target->role === 'owner' && $data['role'] !== 'owner') {
            $this->guardLastOwner($target);
        }

        $old = $target->role;
        $target->update(['role' => $data['role']]);
        $this->record($request, 'portal.user_role_changed', "changed {$target->email} from {$old} to {$target->role}", $target);

        return back()->with('status', 'Role updated.');
    }

    public function toggle(Request $request, int $user): RedirectResponse
    {
        $target = TenantUser::findOrFail($user);
        $this->guardSelf($request, $target);
        if ($target->is_active && $target->role === 'owner') {
            $this->guardLastOwner($target);
        }

        $target->update(['is_active' => ! $target->is_active]);
        $this->record($request, 'portal.user_toggled', ($target->is_active ? 'enabled ' : 'switched off ') . $target->email, $target);

        return back()->with('status', $target->is_active ? 'Access restored.' : 'Access switched off. They are signed out on their next request.');
    }

    /** A fresh link: lets someone who was invited, or who forgot their password, choose a new one. */
    public function reinvite(Request $request, int $user): RedirectResponse
    {
        $target = TenantUser::findOrFail($user);
        $plain = TenantInvitation::issue($target);
        $this->record($request, 'portal.user_reinvited', "issued a new access link for {$target->email}", $target);

        return back()->with('secret', ['label' => "Access link for {$target->email} (valid 7 days)", 'value' => route('portal.invitation', $plain)]);
    }

    public function resetTwoFactor(Request $request, int $user): RedirectResponse
    {
        $target = TenantUser::findOrFail($user);
        $this->guardSelf($request, $target);

        $target->forceFill(['totp_secret' => null, 'totp_confirmed_at' => null, 'totp_last_step' => null])->save();
        $this->record($request, 'portal.user_2fa_reset', "reset two-factor for {$target->email}", $target);

        return back()->with('status', 'Two-factor reset. They set it up again at their next sign-in.');
    }

    public function destroy(Request $request, int $user): RedirectResponse
    {
        $target = TenantUser::findOrFail($user);
        $this->guardSelf($request, $target);
        if ($target->role === 'owner') {
            $this->guardLastOwner($target);
        }

        $this->record($request, 'portal.user_removed', "removed {$target->email}", $target);
        $target->delete();

        return back()->with('status', 'User removed.');
    }

    private function guardSelf(Request $request, TenantUser $target): void
    {
        if ($request->user('tenant')->is($target)) {
            throw ValidationException::withMessages(['user' => 'You cannot do this to your own account. Ask another owner.']);
        }
    }

    /** The tenant must always keep at least one active owner, or nobody could manage the team. */
    private function guardLastOwner(TenantUser $target): void
    {
        $others = TenantUser::where('role', 'owner')->where('is_active', true)->where('id', '!=', $target->id)->count();
        if ($others === 0) {
            throw ValidationException::withMessages(['user' => 'This is the only active owner. Make someone else an owner first.']);
        }
    }

    private function record(Request $request, string $event, string $what, TenantUser $subject): void
    {
        $actor = $request->user('tenant');
        $this->audit->record($actor->tenant_id, $event, 'tenant_user', $actor->id, TenantUser::class, $subject->id, "{$actor->email} {$what}");
    }
}
