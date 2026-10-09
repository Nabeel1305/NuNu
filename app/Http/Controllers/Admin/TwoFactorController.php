<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Services\Auth\Totp;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** The second step of signing in, and the page where an admin sets up their authenticator. */
class TwoFactorController extends Controller
{
    private const MAX_ATTEMPTS = 5;
    private const DECAY_SECONDS = 900;

    // ── Second step of sign-in ────────────────────────────────────────────────

    public function challenge(Request $request): View|RedirectResponse
    {
        return $this->pending($request) ? view('admin.two-factor') : redirect()->route('admin.login');
    }

    public function verify(Request $request): RedirectResponse
    {
        $pending = $this->pending($request);

        if (! $pending) {
            return redirect()->route('admin.login')->withErrors(['email' => 'Sign in again.']);
        }

        $data = $request->validate(['code' => ['required', 'string', 'max:12']]);
        $admin = Admin::find($pending['id']);
        $key = 'admin-2fa:' . $admin->id . '|' . $request->ip();
        $accountKey = 'admin-2fa-account:' . $admin->id;

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)
            || RateLimiter::tooManyAttempts($accountKey, (int) config('platform.login.second_factor_max_failures'))) {
            $request->session()->forget('admin_2fa');

            throw ValidationException::withMessages(['code' => 'Too many attempts. Sign in again later.']);
        }

        $step = $this->matchStep($admin, $data['code']);

        if ($step === null) {
            RateLimiter::hit($key, self::DECAY_SECONDS);
            RateLimiter::hit($accountKey, (int) config('platform.login.second_factor_decay_seconds'));

            throw ValidationException::withMessages(['code' => 'That code is not valid.']);
        }

        RateLimiter::clear($accountKey);

        RateLimiter::clear($key);
        $admin->forceFill(['totp_last_step' => $step])->save();

        $request->session()->forget('admin_2fa');
        Auth::guard('admin')->login($admin, $pending['remember']);
        $request->session()->regenerate();

        return redirect()->intended(route('admin.tenants.index'));
    }

    // ── Setting up an authenticator ───────────────────────────────────────────

    public function setup(Request $request): View
    {
        $admin = $request->user('admin');

        if (! $admin->hasTwoFactor() && $admin->totp_secret === null) {
            $admin->forceFill(['totp_secret' => Totp::generateSecret()])->save();
        }

        return view('admin.security', [
            'admin' => $admin,
            'enabled' => $admin->hasTwoFactor(),
            'secret' => $admin->hasTwoFactor() ? null : $admin->totp_secret,
            'uri' => $admin->hasTwoFactor() ? null : Totp::uri(config('app.name'), $admin->email, $admin->totp_secret),
            'required' => \App\Http\Middleware\EnsureTwoFactor::required(),
        ]);
    }

    public function confirm(Request $request): RedirectResponse
    {
        $admin = $request->user('admin');

        if ($admin->hasTwoFactor() || $admin->totp_secret === null) {
            return redirect()->route('admin.security');
        }

        $data = $request->validate(['code' => ['required', 'string', 'max:12']]);
        $key = 'admin-2fa-setup:' . $admin->id;

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            throw ValidationException::withMessages(['code' => 'Too many attempts. Try again later.']);
        }

        $step = Totp::verify($admin->totp_secret, $data['code'], now()->timestamp);

        if ($step === null) {
            RateLimiter::hit($key, self::DECAY_SECONDS);

            throw ValidationException::withMessages(['code' => 'That code is not valid. Check the clock on your phone and try the next code.']);
        }

        RateLimiter::clear($key);
        $admin->forceFill(['totp_confirmed_at' => now(), 'totp_last_step' => $step])->save();

        return redirect()->route('admin.security')->with('status', 'Two-factor authentication is on. It cannot be switched off from the dashboard; an operator with server access can reset it.');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /** @return array{id:int, remember:bool, until:int}|null */
    private function pending(Request $request): ?array
    {
        $pending = $request->session()->get('admin_2fa');

        return ($pending && $pending['until'] >= now()->timestamp) ? $pending : null;
    }

    private function matchStep(Admin $admin, string $code): ?int
    {
        return Totp::verify($admin->totp_secret, $code, now()->timestamp, $admin->totp_last_step);
    }
}
