@extends('layouts.portal')
@section('title', 'Settings')
@section('content')
<div class="mb-4">
    <h4 class="fw-bold mb-1"><i class="ti ti-settings me-2" style="color:var(--brand-accent);"></i>Settings</h4>
    <p class="text-muted mb-0">How PakaPay has set up your organisation. To change anything except the offline-payment choices below, contact PakaPay support.</p>
</div>
<div class="row g-4 mb-4">
    <div class="col-lg-6"><div class="card h-100"><div class="card-header"><span class="section-title mb-0 mt-0">Organisation</span></div><div class="card-body"><dl class="dl">
        <dt>Name</dt><dd>{{ $tenant->name }}</dd>
        <dt>Identifier</dt><dd><code>{{ $tenant->slug }}</code></dd>
        <dt>Status</dt><dd><span class="{{ $tenant->isActive() ? 'badge-success-soft' : 'badge-danger-soft' }}">{{ $tenant->status }}</span></dd>
        <dt>Environment</dt><dd><span class="env-pill {{ $tenant->isLive() ? 'is-live' : 'is-sandbox' }}">{{ $tenant->environment }}</span></dd>
        <dt>Settlement adapter</dt><dd>{{ $tenant->settlement_adapter }}</dd>
    </dl></div></div></div>
    <div class="col-lg-6"><div class="card h-100"><div class="card-header"><span class="section-title mb-0 mt-0">Payment codes</span></div><div class="card-body"><dl class="dl">
        <dt>Code lifetime</dt><dd>{{ $tenant->code_ttl_minutes }} minutes</dd>
        <dt>Caller check</dt><dd>{{ $tenant->bind_caller ? 'Code only works from the payer\'s registered phone number' : 'Off: the code alone authorises the payment' }}</dd>
        <dt>Largest payment</dt><dd>{{ $maxAmount ?? 'No limit set' }}</dd>
        <dt>Offline phone codes</dt><dd>@if($deviceCodes->enabled)<span class="badge-success-soft">on</span> <span class="kv">up to {{ \App\Support\Money::format($deviceCodes->maxAmountMinor, 'NGN') }} each, {{ \App\Support\Money::format($deviceCodes->dailyLimitMinor, 'NGN') }} a day per subscriber</span>@else<span class="badge-theme">off</span>@endif</dd>
        @if($deviceCodes->enabled)<dt>Pay any account offline</dt><dd>@if($deviceCodes->directEnabled)<span class="badge-success-soft">on</span> <span class="kv">up to {{ \App\Support\Money::format($deviceCodes->directMaxAmountMinor, 'NGN') }} each to an account the phone has not looked up</span>@else<span class="badge-theme">off</span>@endif</dd>@endif
        <dt>Voice mode</dt><dd>{{ $tenant->voice_mode === 'shared' ? 'Shared number (codes start with ' . $tenant->short_code . ')' : 'Your own number' }}</dd>
    </dl></div></div></div>
</div>
@if($deviceCodes->enabled && auth('tenant')->user()->can('manage_settings'))
<div class="card mb-4">
    <div class="card-header"><span class="section-title mb-0 mt-0"><i class="ti ti-phone-off"></i>Pay any account with no connection</span></div>
    <div class="card-body">
        <p class="text-muted">When on, a customer's phone can pay any bank account without having looked it up first: the account number is part of the code it dials. Nothing confirms the account holder's name offline, so set a cap you are comfortable with. Without this, a phone can pay offline only accounts it has seen while connected.</p>
        @if(session('status'))<div class="alert alert-success py-2">{{ session('status') }}</div>@endif
        <form method="post" action="{{ route('portal.settings.direct-codes') }}" class="row g-3 align-items-end">
            @csrf @method('PUT')
            <div class="col-md-4"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="direct_enabled" value="1" id="direct_enabled" @checked(old('direct_enabled', $deviceCodes->directEnabled))><label class="form-check-label" for="direct_enabled">Allow</label></div></div>
            <div class="col-md-5"><label class="form-label" for="direct_max_naira">Largest payment to an account not looked up (₦)</label><input id="direct_max_naira" name="direct_max_naira" type="number" step="0.01" min="1" max="{{ $deviceCodes->maxAmountMinor / 100 }}" class="form-control @error('direct_max_naira') is-invalid @enderror" value="{{ old('direct_max_naira', $deviceCodes->directMaxAmountMinor / 100) }}">@error('direct_max_naira')<div class="invalid-feedback">{{ $message }}</div>@enderror<div class="form-text">At most {{ \App\Support\Money::format($deviceCodes->maxAmountMinor, 'NGN') }}, your overall offline cap.</div></div>
            <div class="col-md-3"><button class="btn btn-primary w-100">Save</button></div>
        </form>
    </div>
</div>
@endif
<div class="card mb-4">
    <div class="card-header"><span class="section-title mb-0 mt-0"><i class="ti ti-phone-call"></i>Voice numbers</span></div>
    <div class="table-responsive"><table class="table">
        <thead><tr><th>Number</th><th>Provider</th><th>Status</th></tr></thead>
        <tbody>
        @forelse($numbers as $n)<tr><td>{{ $n->number }}</td><td>{{ $n->provider }}</td><td><span class="{{ $n->active ? 'badge-success-soft' : 'badge-danger-soft' }}">{{ $n->active ? 'active' : 'disabled' }}</span></td></tr>
        @empty<tr><td colspan="3" class="text-muted">@if($tenant->voice_mode === 'shared')You use the shared PakaPay number(s).@else No number assigned yet.@endif</td></tr>@endforelse
        </tbody>
    </table></div>
</div>
<div class="card">
    <div class="card-header"><span class="section-title mb-0 mt-0"><i class="ti ti-terminal-2"></i>Quick start</span></div>
    <div class="card-body">
        <p class="text-muted">Base URL: <code>{{ $apiBase }}</code>. Authenticate with an <a href="{{ route('portal.keys') }}">API key</a>.</p>
        <pre class="payload">curl {{ $apiBase }}/codes \
  -H "Authorization: Bearer opk_…" \
  -H "Idempotency-Key: $(uuidgen)" \
  -H "Content-Type: application/json" \
  -d '{
    "subscriber_reference": "cust-123",
    "merchant_reference": "shop-9",
    "amount_minor": 250000,
    "currency": "NGN"
  }'</pre>
    </div>
</div>
@endsection
