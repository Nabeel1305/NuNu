@extends('layouts.portal')
@section('title', 'Settings')
@section('content')
<div class="mb-4">
    <h4 class="fw-bold mb-1"><i class="ti ti-settings me-2" style="color:var(--brand-accent);"></i>Settings</h4>
    <p class="text-muted mb-0">How PakaPay has set up your organisation. To change any of this, contact PakaPay support.</p>
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
        <dt>Voice mode</dt><dd>{{ $tenant->voice_mode === 'shared' ? 'Shared number (codes start with ' . $tenant->short_code . ')' : 'Your own number' }}</dd>
    </dl></div></div></div>
</div>
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
