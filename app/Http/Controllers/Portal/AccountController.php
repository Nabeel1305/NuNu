<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureTwoFactor;
use App\Services\Audit\AuditLogService;
use App\Services\Auth\Totp;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** The signed-in person's own security: authenticator app and password. */
class AccountController extends Controller
{
    private const MAX_ATTEMPTS = 5;
    private const DECAY_SECONDS = 900;

    public function __construct(private readonly AuditLogService $audit)
    {
    }

    public function show(Request $request): View
    {
        $user = $request->user('tenant');
        if (! $user->hasTwoFactor() && $user->totp_secret === null) {
            $user->forceFill(['totp_secret' => Totp::generateSecret()])->save();
        }

        return view('portal.account', [
            'user' => $user,
            'enabled' => $user->hasTwoFactor(),
            'secret' => $user->hasTwoFactor() ? null : $user->totp_secret,
            'uri' => $user->hasTwoFactor() ? null : Totp::uri(config('app.name') . ' Portal', $user->email, $user->totp_secret),
            'required' => EnsureTwoFactor::required(),
        ]);
    }

    public function confirmTwoFactor(Request $request): RedirectResponse
    {
        $user = $request->user('tenant');
        if ($user->hasTwoFactor() || $user->totp_secret === null) {
            return redirect()->route('portal.account');
        }

        $data = $request->validate(['code' => ['required', 'string', 'max:12']]);
        $key = 'portal-2fa-setup:' . $user->id;
        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            throw ValidationException::withMessages(['code' => 'Too many attempts. Try again later.']);
        }

        $step = Totp::verify($user->totp_secret, $data['code'], now()->timestamp);
        if ($step === null) {
            RateLimiter::hit($key, self::DECAY_SECONDS);
            throw ValidationException::withMessages(['code' => 'That code is not valid. Check the clock on your phone and try the next code.']);
        }

        RateLimiter::clear($key);
        $user->forceFill(['totp_confirmed_at' => now(), 'totp_last_step' => $step])->save();
        $this->audit->record($user->tenant_id, 'portal.2fa_enabled', 'tenant_user', $user->id, $user::class, $user->id, "{$user->email} turned on two-factor");

        return redirect()->route('portal.account')->with('status', 'Two-factor authentication is on. If you lose your phone, an owner can reset it for you.');
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $user = $request->user('tenant');
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'confirmed', Password::min(12), 'different:current_password'],
        ]);

        $key = 'portal-pw:' . $user->id;
        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            throw ValidationException::withMessages(['current_password' => 'Too many attempts. Try again later.']);
        }
        if (! Hash::check($data['current_password'], $user->password)) {
            RateLimiter::hit($key, self::DECAY_SECONDS);
            throw ValidationException::withMessages(['current_password' => 'That is not your current password.']);
        }

        RateLimiter::clear($key);
        $user->update(['password' => $data['password']]);
        $this->audit->record($user->tenant_id, 'portal.password_changed', 'tenant_user', $user->id, $user::class, $user->id, "{$user->email} changed their password");

        return back()->with('status', 'Password changed.');
    }
}
