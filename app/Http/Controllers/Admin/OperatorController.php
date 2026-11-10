<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Who can sign in to this console. Only an owner gets here (admin.can:operators). */
class OperatorController extends Controller
{
    public function index(): View
    {
        return view('admin.operators.index', ['operators' => Admin::orderBy('name')->get()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:191', 'unique:admins,email', 'unique:tenant_users,email'],
            'role' => ['required', Rule::in(Admin::ROLES)],
        ]);

        $temp = self::temporaryPassword();
        Admin::create($data + ['password' => $temp, 'is_active' => true, 'must_change_password' => true]);

        return back()->with('secret', ['label' => "Temporary password for {$data['email']} (they must change it at first sign-in)", 'value' => $temp]);
    }

    public function role(Request $request, Admin $admin): RedirectResponse
    {
        $data = $request->validate(['role' => ['required', Rule::in(Admin::ROLES)]]);
        $this->guardLastOwner($request, $admin, $data['role'] === 'owner');
        $admin->update($data);
        $admin->increment('session_epoch');       // their open sessions pick up the new role at once

        return back()->with('status', 'Role changed.');
    }

    public function toggle(Request $request, Admin $admin): RedirectResponse
    {
        abort_if($admin->id === $request->user('admin')->id, 422, 'You cannot switch yourself off.');
        $this->guardLastOwner($request, $admin, $admin->is_active ? false : true);
        $admin->update(['is_active' => ! $admin->is_active]);
        $admin->increment('session_epoch');

        return back()->with('status', $admin->is_active ? 'Restored.' : 'Switched off and signed out.');
    }

    public function resetTwoFactor(Admin $admin): RedirectResponse
    {
        $admin->forceFill(['totp_secret' => null, 'totp_confirmed_at' => null, 'totp_last_step' => null, 'session_epoch' => $admin->session_epoch + 1])->save();

        return back()->with('status', 'Two-factor reset. They set it up again at next sign-in.');
    }

    public function resetPassword(Admin $admin): RedirectResponse
    {
        $temp = self::temporaryPassword();
        $admin->forceFill(['password' => $temp, 'must_change_password' => true, 'session_epoch' => $admin->session_epoch + 1])->save();

        return back()->with('secret', ['label' => "New temporary password for {$admin->email}", 'value' => $temp]);
    }

    public function destroy(Request $request, Admin $admin): RedirectResponse
    {
        abort_if($admin->id === $request->user('admin')->id, 422, 'You cannot remove yourself.');
        $this->guardLastOwner($request, $admin, false);
        $admin->delete();     // the platform audit log keeps their email on every row they wrote

        return back()->with('status', 'Removed.');
    }

    /** At least one active owner must always remain. */
    private function guardLastOwner(Request $request, Admin $target, bool $staysOwner): void
    {
        if ($target->isOwner() && $target->is_active && ! $staysOwner) {
            $others = Admin::where('role', 'owner')->where('is_active', true)->where('id', '!=', $target->id)->count();
            abort_if($others === 0, 422, 'There must always be one active owner.');
        }
    }

    private static function temporaryPassword(): string
    {
        return Str::password(16, symbols: false);
    }
}
