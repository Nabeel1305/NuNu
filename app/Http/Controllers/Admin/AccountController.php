<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/** An operator's own password and sessions. */
class AccountController extends Controller
{
    public function password(Request $request): View
    {
        return view('admin.password', ['forced' => (bool) $request->user('admin')->must_change_password]);
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $admin = $request->user('admin');
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'confirmed', Password::min(12)->letters()->numbers()],
        ]);

        if (! Hash::check($data['current_password'], $admin->password)) {
            return back()->withErrors(['current_password' => 'That is not your current password.']);
        }
        if (Hash::check($data['password'], $admin->password)) {
            return back()->withErrors(['password' => 'Choose a password you have not used just now.']);
        }

        // Changing the password signs every other session out: bumping the epoch, then keeping this one.
        $admin->forceFill([
            'password' => $data['password'], 'must_change_password' => false, 'password_changed_at' => now(),
            'session_epoch' => $admin->session_epoch + 1,
        ])->save();
        $request->session()->put('admin_epoch', $admin->session_epoch);
        $request->session()->regenerate();

        return redirect()->route('admin.dashboard')->with('status', 'Password changed. Other sessions were signed out.');
    }

    public function signOutEverywhere(Request $request): RedirectResponse
    {
        $admin = $request->user('admin');
        $admin->forceFill(['session_epoch' => $admin->session_epoch + 1])->save();
        $request->session()->put('admin_epoch', $admin->session_epoch);

        return back()->with('status', 'Every other session of yours was signed out.');
    }
}
