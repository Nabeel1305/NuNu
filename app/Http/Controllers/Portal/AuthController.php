<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\TenantInvitation;
use App\Models\TenantUser;
use App\Services\Audit\AuditLogService;
use App\Services\Auth\Totp;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** Sign-in, second factor and invitation links for tenant staff. */
class AuthController extends Controller
{
    private const MAX_ATTEMPTS = 5;
    private const DECAY_SECONDS = 900;

    public function __construct(private readonly AuditLogService $audit)
    {
    }

    public function show(): View|RedirectResponse
    {
        return Auth::guard('tenant')->check() ? redirect()->route('portal.dashboard') : view('portal.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate(['email' => ['required', 'email'], 'password' => ['required', 'string']]);

        $key = 'portal-login:' . sha1(strtolower($credentials['email']) . '|' . $request->ip());
        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            $minutes = (int) ceil(RateLimiter::availableIn($key) / 60);
            throw ValidationException::withMessages(['email' => "Too many attempts. Try again in {$minutes} minute(s)."]);
        }

        $user = TenantUser::withoutGlobalScopes()->where('email', $credentials['email'])->first();
        if ($user && $user->hasAccepted()) {
            $passwordOk = Hash::check($credentials['password'], $user->password);
        } else {
            Hash::make($credentials['password']); // an unknown email costs the same as a wrong password
            $passwordOk = false;
        }

        if (! $user || ! $passwordOk || ! $user->is_active || ! Tenant::whereKey($user->tenant_id)->exists()) {
            RateLimiter::hit($key, self::DECAY_SECONDS);
            throw ValidationException::withMessages(['email' => 'These credentials do not match.']);
        }

        RateLimiter::clear($key);

        if ($user->hasTwoFactor()) {
            $request->session()->regenerate();
            $request->session()->put('tenant_2fa', ['id' => $user->id, 'remember' => $request->boolean('remember'), 'until' => now()->addMinutes(5)->timestamp]);

            return redirect()->route('portal.two-factor');
        }

        return $this->signIn($request, $user, $request->boolean('remember'));
    }

    public function challenge(Request $request): View|RedirectResponse
    {
        return $this->pending($request) ? view('portal.two-factor') : redirect()->route('portal.login');
    }

    public function verify(Request $request): RedirectResponse
    {
        $pending = $this->pending($request);
        if (! $pending) {
            return redirect()->route('portal.login')->withErrors(['email' => 'Sign in again.']);
        }

        $data = $request->validate(['code' => ['required', 'string', 'max:12']]);
        $user = TenantUser::withoutGlobalScopes()->find($pending['id']);

        $key = 'portal-2fa:' . $user->id . '|' . $request->ip();
        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            $request->session()->forget('tenant_2fa');
            throw ValidationException::withMessages(['code' => 'Too many attempts. Sign in again later.']);
        }

        $step = Totp::verify($user->totp_secret, $data['code'], now()->timestamp, $user->totp_last_step);
        if ($step === null) {
            RateLimiter::hit($key, self::DECAY_SECONDS);
            throw ValidationException::withMessages(['code' => 'That code is not valid.']);
        }

        RateLimiter::clear($key);
        $user->forceFill(['totp_last_step' => $step])->save();
        $request->session()->forget('tenant_2fa');

        return $this->signIn($request, $user, $pending['remember']);
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::guard('tenant')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('portal.login');
    }

    // ── Invitation links ──────────────────────────────────────────────────────

    public function invitation(string $token): View
    {
        $invitation = TenantInvitation::findOpen($token);
        abort_if($invitation === null, 404);

        return view('portal.invite', ['user' => $invitation->user, 'token' => $token]);
    }

    public function accept(Request $request, string $token): RedirectResponse
    {
        $invitation = TenantInvitation::findOpen($token);
        abort_if($invitation === null, 404);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'password' => ['required', 'confirmed', Password::min(12)],
        ]);

        $user = $invitation->user;
        $user->forceFill(['name' => $data['name'], 'password' => $data['password'], 'is_active' => true])->save();
        $invitation->forceFill(['accepted_at' => now()])->save();

        $this->audit->record($user->tenant_id, 'portal.invitation_accepted', 'tenant_user', $user->id, TenantUser::class, $user->id, "{$user->email} set a password");

        return $this->signIn($request, $user, false);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function signIn(Request $request, TenantUser $user, bool $remember): RedirectResponse
    {
        Auth::guard('tenant')->login($user, $remember);
        $request->session()->regenerate();
        $user->forceFill(['last_login_at' => now()])->save();

        $this->audit->record($user->tenant_id, 'portal.login', 'tenant_user', $user->id, TenantUser::class, $user->id, "{$user->email} signed in", ['ip' => $request->ip()]);

        return redirect()->intended(route('portal.dashboard'));
    }

    /** @return array{id:int, remember:bool, until:int}|null */
    private function pending(Request $request): ?array
    {
        $pending = $request->session()->get('tenant_2fa');

        return ($pending && $pending['until'] >= now()->timestamp) ? $pending : null;
    }
}
