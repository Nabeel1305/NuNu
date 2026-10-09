@extends('layouts.portal')
@section('title', 'My account')
@section('content')
<div class="mb-4">
    <h4 class="fw-bold mb-1"><i class="ti ti-shield-lock me-2" style="color:var(--brand-accent);"></i>My account</h4>
    <p class="text-muted mb-0">{{ $user->name }} · {{ $user->email }} · <span class="text-capitalize">{{ $user->role }}</span></p>
</div>
<div class="row g-4">
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header"><span class="section-title mb-0 mt-0">Two-factor authentication</span></div>
            <div class="card-body">
            @if($enabled)
                <p><span class="badge-success-soft"><i class="ti ti-check"></i> On</span></p>
                <p class="mb-0">You are asked for a code from your authenticator app each time you sign in. If you lose your phone, an owner of your organisation can reset it.</p>
            @else
                @if($required)<p class="badge-warning-soft mb-3">Two-factor authentication is required. Finish setup to use the portal.</p>@endif
                <p><strong>1.</strong> Add this account to an authenticator app (Google Authenticator, 1Password, Authy…). Enter this key by hand:</p>
                <div class="card secret-card mb-3"><div class="card-body py-2"><code style="margin:0">{{ chunk_split($secret, 4, ' ') }}</code></div></div>
                <p class="text-muted">Or open this address on the phone that has the app: <code style="word-break:break-all">{{ $uri }}</code></p>
                <p><strong>2.</strong> Enter the 6-digit code the app now shows.</p>
                <form method="post" action="{{ route('portal.account.two-factor') }}" style="max-width:280px">@csrf
                    <label class="form-label" for="code">Code</label>
                    <input id="code" name="code" class="form-control mb-3" inputmode="numeric" autocomplete="one-time-code" maxlength="12" required>
                    <button class="btn btn-brand">Turn on two-factor</button>
                </form>
            @endif
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header"><span class="section-title mb-0 mt-0">Password</span></div>
            <div class="card-body">
                <form method="post" action="{{ route('portal.account.password') }}">@csrf
                    <div class="mb-3"><label class="form-label" for="current_password">Current password</label><input id="current_password" name="current_password" type="password" class="form-control" required autocomplete="current-password"></div>
                    <div class="mb-3"><label class="form-label" for="password">New password (12+ characters)</label><input id="password" name="password" type="password" class="form-control" required minlength="12" autocomplete="new-password"></div>
                    <div class="mb-3"><label class="form-label" for="password_confirmation">Repeat new password</label><input id="password_confirmation" name="password_confirmation" type="password" class="form-control" required minlength="12" autocomplete="new-password"></div>
                    <button class="btn btn-brand">Change password</button>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
