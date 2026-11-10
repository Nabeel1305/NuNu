<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AuthController extends Controller
{
    private const MAX_ATTEMPTS = 5;
    private const DECAY_SECONDS = 900;

    public function show(): View|RedirectResponse
    {
        return Auth::guard('admin')->check() ? redirect()->route('admin.tenants.index') : view('admin.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate(['email' => ['required', 'email'], 'password' => ['required', 'string']]);

        // Keyed on email and address together, so one person cannot lock a
        // real admin out from another network just by guessing at the email.
        $key = 'admin-login:' . sha1(strtolower($credentials['email']) . '|' . $request->ip());

        // And per account across ALL addresses, so guessing cannot be spread over many of them.
        $accountKey = 'admin-login-account:' . sha1(strtolower($credentials['email']));
        $accountMax = (int) config('platform.login.account_max_failures');
        foreach ([[$key, self::MAX_ATTEMPTS], [$accountKey, $accountMax]] as [$k, $max]) {
            if (RateLimiter::tooManyAttempts($k, $max)) {
                $minutes = (int) ceil(RateLimiter::availableIn($k) / 60);

                throw ValidationException::withMessages(['email' => "Too many attempts. Try again in {$minutes} minute(s)."]);
            }
        }

        $admin = Admin::where('email', $credentials['email'])->first();
        if ($admin) {
            $passwordOk = Hash::check($credentials['password'], $admin->password);
        } else {
            // Hash something anyway: it costs the same as a real check, so an unknown
            // email and a wrong password take the same time.
            Hash::make($credentials['password']);
            $passwordOk = false;
        }

        if (! $admin || ! $passwordOk || ! $admin->is_active) {
            RateLimiter::hit($key, self::DECAY_SECONDS);
            RateLimiter::hit($accountKey, (int) config('platform.login.account_decay_seconds'));

            \App\Support\PlatformAudit::record(null, 'admin.login_failed', 'Sign-in refused', null, null, [], 'failed', $request->ip(), $credentials['email']);

            // The same message whether the email exists, the password is wrong or the admin is inactive.
            throw ValidationException::withMessages(['email' => 'These credentials do not match.']);
        }

        RateLimiter::clear($key);

        if ($admin->hasTwoFactor()) {
            // Password is right but nobody is signed in yet: remember who, for five minutes.
            $request->session()->regenerate();
            $request->session()->put('admin_2fa', ['id' => $admin->id, 'remember' => $request->boolean('remember'), 'until' => now()->addMinutes(5)->timestamp]);

            return redirect()->route('admin.two-factor');
        }

        Auth::guard('admin')->login($admin, $request->boolean('remember'));
        $request->session()->regenerate();
        self::began($request, $admin);

        return redirect()->intended(route('admin.tenants.index'));
    }

    /** Called once an operator is fully signed in (after the second factor too). */
    public static function began(Request $request, \App\Models\Admin $admin): void
    {
        $admin->forceFill(['last_login_at' => now()])->save();
        $request->session()->put('admin_epoch', $admin->session_epoch);
        $request->session()->put('admin_last_activity', now()->timestamp);
        \App\Support\PlatformAudit::record($admin, 'admin.login', 'Signed in', null, null, ['two_factor' => $admin->hasTwoFactor()]);
    }

    public function logout(Request $request): RedirectResponse
    {
        \App\Support\PlatformAudit::record($request->user('admin'), 'admin.logout', 'Signed out');
        Auth::guard('admin')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login');
    }
}
