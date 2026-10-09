@extends('layouts.admin')
@section('title', $tenant->name)
@section('breadcrumb', $tenant->name)
@section('content')
<div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
    <div>
        <h4 class="fw-bold mb-1"><i class="ti ti-building me-2" style="color:var(--brand-accent);"></i>{{ $tenant->name }}</h4>
        <p class="text-muted mb-0"><code>{{ $tenant->slug }}</code>
            <span class="{{ $tenant->status === 'active' ? 'badge-success-soft' : 'badge-danger-soft' }} ms-2">{{ $tenant->status }}</span>
            <span class="{{ $tenant->environment === 'live' ? 'badge-info-soft' : 'badge-theme' }} ms-1">{{ $tenant->environment }}</span></p>
    </div>
    <a href="{{ route('admin.tenants.index') }}" class="btn btn-brand-outline btn-sm"><i class="ti ti-arrow-left me-1"></i>All tenants</a>
</div>

@if ($pendingTransactions > 0)
<div class="alert mb-4" style="background:var(--warning-bg);color:var(--warning);border:1px solid var(--border);border-radius:var(--radius)">
    <i class="ti ti-clock-hour-4 me-1"></i>{{ $pendingTransactions }} transaction(s) are waiting on the tenant's settlement system. Reconciliation runs every five minutes; any still unresolved after a day are flagged in the audit log.
</div>
@endif

<div class="card mb-4">
    <div class="card-header"><span class="section-title mb-0 mt-0"><i class="ti ti-settings"></i>Settings</span></div>
    <div class="card-body">
        <form method="post" action="{{ route('admin.tenants.update', $tenant) }}">
            @csrf @method('PUT')
            <div class="row g-3">
                <div class="col-md-4"><label class="form-label" for="status">Status</label>
                    <select id="status" name="status" class="form-select">@foreach (['active','suspended'] as $v)<option value="{{ $v }}" @selected(old('status',$tenant->status)===$v)>{{ $v }}</option>@endforeach</select></div>
                <div class="col-md-4"><label class="form-label" for="environment">Environment</label>
                    <select id="environment" name="environment" class="form-select">@foreach (['sandbox','live'] as $v)<option value="{{ $v }}" @selected(old('environment',$tenant->environment)===$v)>{{ $v }}</option>@endforeach</select></div>
                <div class="col-md-4"><label class="form-label" for="settlement_adapter">Settlement adapter</label>
                    <select id="settlement_adapter" name="settlement_adapter" class="form-select">@foreach ($adapters as $v)<option value="{{ $v }}" @selected(old('settlement_adapter',$tenant->settlement_adapter)===$v)>{{ $v }}</option>@endforeach</select></div>
                <div class="col-md-4"><label class="form-label" for="voice_mode">Voice number</label>
                    <select id="voice_mode" name="voice_mode" class="form-select">@foreach (['own','shared'] as $v)<option value="{{ $v }}" @selected(old('voice_mode',$tenant->voice_mode)===$v)>{{ $v }}</option>@endforeach</select></div>
                <div class="col-md-4"><label class="form-label" for="code_ttl_minutes">Code lifetime (minutes)</label><input id="code_ttl_minutes" name="code_ttl_minutes" type="number" min="1" max="60" class="form-control" value="{{ old('code_ttl_minutes',$tenant->code_ttl_minutes) }}"></div>
                <div class="col-md-4"><label class="form-label" for="max_amount_minor">Max amount (minor units, optional)</label><input id="max_amount_minor" name="max_amount_minor" type="number" min="1" class="form-control" value="{{ old('max_amount_minor',$tenant->setting('max_amount_minor')) }}"></div>
            </div>
            <div class="form-check mt-3">
                <input class="form-check-input" type="checkbox" name="bind_caller" value="1" id="bind_caller" @checked(old('bind_caller',$tenant->bind_caller))>
                <label class="form-check-label" for="bind_caller">Only accept a code from the payer's registered phone number</label>
            </div>
            @if ($tenant->voice_mode === 'shared')<p class="text-muted mt-3 mb-0">Shared-pool short code: <strong>{{ $tenant->short_code }}</strong> (prefixed to every code).</p>@endif
            <button class="btn btn-brand mt-3">Save settings</button>
        </form>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><span class="section-title mb-0 mt-0"><i class="ti ti-key"></i>API keys</span></div>
    <div class="table-responsive">
        <table class="table">
            <thead><tr><th>Name</th><th>Key</th><th>Last used</th><th class="text-end"></th></tr></thead>
            <tbody>
            @foreach ($keys as $key)
                <tr>
                    <td>{{ $key->name }}</td>
                    <td><code>opk_{{ $key->prefix }}_…</code> @if($key->revoked_at)<span class="badge-danger-soft ms-1">revoked</span>@endif</td>
                    <td class="text-muted">{{ $key->last_used_at?->diffForHumans() ?? 'never' }}</td>
                    <td class="text-end">@unless($key->revoked_at)
                        <form class="d-inline" method="post" action="{{ route('admin.keys.destroy', [$tenant, $key]) }}" data-confirm="Anything using this key stops working at once." data-confirm-label="Revoke key">@csrf @method('DELETE')
                            <input type="hidden" name="confirm" value="1">
                            <button class="btn btn-sm btn-brand-outline" style="color:var(--danger)">Revoke</button></form>
                    @endunless</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
    <div class="card-body border-top">
        <form method="post" action="{{ route('admin.keys.store', $tenant) }}" class="row g-2 align-items-end">
            @csrf
            <div class="col-md-5"><label class="form-label" for="key_name">New key name</label><input id="key_name" name="name" class="form-control" required maxlength="60"></div>
            <div class="col-auto"><button class="btn btn-brand">Issue key</button></div>
        </form>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><span class="section-title mb-0 mt-0"><i class="ti ti-users"></i>Portal users</span></div>
    <div class="table-responsive">
        <table class="table">
            <thead><tr><th>Person</th><th>Role</th><th>Status</th><th>2FA</th><th class="text-end"></th></tr></thead>
            <tbody>
            @forelse ($portalUsers as $u)
                <tr>
                    <td><div class="fw-semibold">{{ $u->name }}</div><div class="kv">{{ $u->email }}</div></td>
                    <td class="text-capitalize">{{ $u->role }}</td>
                    <td>@if(! $u->hasAccepted())<span class="badge-warning-soft">invited</span>@elseif($u->is_active)<span class="badge-success-soft">active</span>@else<span class="badge-danger-soft">off</span>@endif</td>
                    <td>{{ $u->hasTwoFactor() ? 'on' : 'off' }}</td>
                    <td class="text-end">
                        <form class="d-inline" method="post" action="{{ route('admin.portal-users.reinvite', [$tenant, $u->id]) }}">@csrf<button class="btn btn-sm btn-brand-outline">New access link</button></form>
                        @if($u->hasTwoFactor())<form class="d-inline" method="post" action="{{ route('admin.portal-users.reset-2fa', [$tenant, $u->id]) }}" data-confirm="They will set up their authenticator again at next sign-in." data-confirm-label="Reset 2FA">@csrf<button class="btn btn-sm btn-brand-outline">Reset 2FA</button></form>@endif
                        <form class="d-inline" method="post" action="{{ route('admin.portal-users.toggle', [$tenant, $u->id]) }}">@csrf @method('PATCH')<button class="btn btn-sm btn-brand-outline">{{ $u->is_active ? 'Switch off' : 'Restore' }}</button></form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="text-muted">No one can sign in to this tenant's portal yet. Invite the first owner below.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <div class="card-body border-top">
        <form method="post" action="{{ route('admin.portal-users.store', $tenant) }}" class="row g-2 align-items-end">
            @csrf
            <div class="col-md-3"><label class="form-label" for="pu_name">Name</label><input id="pu_name" name="name" class="form-control" required maxlength="120"></div>
            <div class="col-md-4"><label class="form-label" for="pu_email">Email</label><input id="pu_email" name="email" type="email" class="form-control" required></div>
            <div class="col-md-2"><label class="form-label" for="pu_role">Role</label><select id="pu_role" name="role" class="form-select"><option value="owner">Owner</option><option value="developer">Developer</option><option value="viewer">Viewer</option></select></div>
            <div class="col-auto"><button class="btn btn-brand">Create invitation</button></div>
        </form>
        <p class="text-muted mt-3 mb-0">You get a one-time link to pass to them. The portal is at <code>{{ url('/portal') }}</code>.</p>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><span class="section-title mb-0 mt-0"><i class="ti ti-phone-call"></i>Voice numbers</span></div>
    @if ($tenant->voice_mode === 'shared')<div class="card-body pb-0"><p class="text-muted mb-0">This tenant uses the shared pool. Add shared numbers from the command line: <code>voice-number:add</code> with no tenant.</p></div>@endif
    <div class="table-responsive">
        <table class="table">
            <thead><tr><th>Number</th><th>Provider</th><th>Active</th><th class="text-end"></th></tr></thead>
            <tbody>
            @forelse ($numbers as $number)
                <tr>
                    <td>{{ $number->number }}</td><td>{{ $number->provider }}</td>
                    <td><span class="{{ $number->active ? 'badge-success-soft' : 'badge-danger-soft' }}">{{ $number->active ? 'yes' : 'no' }}</span></td>
                    <td class="text-end">
                        <form class="d-inline" method="post" action="{{ route('admin.numbers.toggle', [$tenant, $number]) }}">@csrf @method('PATCH')<button class="btn btn-sm btn-brand-outline">{{ $number->active ? 'Disable' : 'Enable' }}</button></form>
                        <form class="d-inline" method="post" action="{{ route('admin.numbers.rotate', [$tenant, $number]) }}">@csrf<button class="btn btn-sm btn-brand-outline">Rotate token</button></form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="4" class="text-muted">No numbers.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <div class="card-body border-top">
        <form method="post" action="{{ route('admin.numbers.store', $tenant) }}" class="row g-2 align-items-end">
            @csrf
            <div class="col-md-5"><label class="form-label" for="number">Add number (E.164)</label><input id="number" name="number" class="form-control" placeholder="+2347000000000" required></div>
            <div class="col-auto"><button class="btn btn-brand">Add number</button></div>
        </form>
        <p class="text-muted mt-3 mb-0">Adding a number shows the callback URL once. Paste it into the provider's dashboard.</p>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><span class="section-title mb-0 mt-0"><i class="ti ti-webhook"></i>Recent webhook deliveries</span></div>
    <div class="table-responsive">
        <table class="table">
            <thead><tr><th>Event</th><th>Status</th><th>Attempts</th><th>Last result</th><th>When</th></tr></thead>
            <tbody>
            @forelse ($deliveries as $d)
                <tr>
                    <td>{{ $d->event_type }}</td>
                    <td><span class="{{ ['delivered'=>'badge-success-soft','failed'=>'badge-danger-soft'][$d->status] ?? 'badge-warning-soft' }}">{{ $d->status }}</span></td>
                    <td>{{ $d->attempts }}</td>
                    <td class="text-muted">{{ $d->last_status_code ?? '' }} {{ $d->last_error }}</td>
                    <td class="text-muted">{{ $d->created_at->diffForHumans() }}</td>
                </tr>
            @empty
                <tr><td colspan="5" class="text-muted">None yet.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span class="section-title mb-0 mt-0"><i class="ti ti-history"></i>Audit log</span>
        <form method="post" action="{{ route('admin.tenants.verify-audit', $tenant) }}">@csrf <button class="btn btn-brand-outline btn-sm"><i class="ti ti-shield-check me-1"></i>Verify chain integrity</button></form>
    </div>
    <div class="table-responsive">
        <table class="table">
            <thead><tr><th>#</th><th>Event</th><th>Description</th><th>When</th></tr></thead>
            <tbody>
            @forelse ($audit as $row)
                <tr><td class="text-muted">{{ $row->id }}</td><td>{{ $row->event_type }}</td><td>{{ $row->description }}</td><td class="text-muted">{{ $row->created_at->diffForHumans() }}</td></tr>
            @empty
                <tr><td colspan="4" class="text-muted">Nothing recorded yet.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
