@extends('layouts.admin')
@section('title', 'Security')
@section('content')
<div class="mb-4">
    <h4 class="fw-bold mb-1"><i class="ti ti-shield-lock me-2" style="color:var(--brand-accent);"></i>Your security</h4>
    <p class="text-muted mb-0">Protect your operator account with an authenticator app.</p>
</div>

<div class="card" style="max-width:720px">
    <div class="card-header"><span class="section-title mb-0 mt-0">Two-factor authentication</span></div>
    <div class="card-body">
    @if ($enabled)
        <p><span class="badge-success-soft"><i class="ti ti-check"></i> On</span></p>
        <p>You are asked for a code from your authenticator app each time you sign in.</p>
        <p class="text-muted mb-0">It cannot be switched off here. If you lose your phone, an operator with server access runs <code>php artisan admin:reset-2fa {{ $admin->email }}</code>.</p>
    @else
        @if ($required)<p class="badge-warning-soft mb-3">This deployment requires two-factor authentication. Finish setup to use the dashboard.</p>@endif
        <p><strong>1.</strong> Add this account to an authenticator app (Google Authenticator, 1Password, Authy and others all work). Enter this key by hand:</p>
        <div class="card secret-card mb-3"><div class="card-body py-2"><code style="margin:0">{{ chunk_split($secret, 4, ' ') }}</code></div></div>
        <p class="text-muted">Or open this address on the phone that has the app: <code style="word-break:break-all">{{ $uri }}</code></p>
        <p><strong>2.</strong> Enter the 6-digit code the app now shows, to prove it works.</p>
        <form method="post" action="{{ route('admin.security.confirm') }}" style="max-width:280px">
            @csrf
            <label class="form-label" for="code">Code</label>
            <input id="code" name="code" class="form-control mb-3" inputmode="numeric" autocomplete="one-time-code" maxlength="12" required>
            <button class="btn btn-brand">Turn on two-factor</button>
        </form>
    @endif
    </div>
</div>
@endsection
