@extends('layouts.admin')
@section('title', $tenant->name)
@section('breadcrumb', $tenant->name)
@section('content')
@php $can = auth('admin')->user(); $archived = $tenant->status === 'archived'; @endphp
<div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
    <div>
        <h4 class="fw-bold mb-1"><i class="ti ti-building me-2" style="color:var(--brand-accent);"></i>{{ $tenant->name }}</h4>
        <p class="text-muted mb-0"><code>{{ $tenant->slug }}</code>
            <span class="{{ ['active' => 'badge-success-soft', 'archived' => 'badge-theme'][$tenant->status] ?? 'badge-danger-soft' }} ms-2">{{ $tenant->status }}</span>
            <span class="{{ $tenant->environment === 'live' ? 'badge-info-soft' : 'badge-theme' }} ms-1">{{ $tenant->environment }}</span></p>
    </div>
    <a href="{{ route('admin.tenants.index') }}" class="btn btn-brand-outline btn-sm"><i class="ti ti-arrow-left me-1"></i>All tenants</a>
</div>

@if ($pendingTransactions > 0)
<div class="alert mb-4" style="background:var(--warning-bg);color:var(--warning);border:1px solid var(--border);border-radius:var(--radius)">
    <i class="ti ti-clock-hour-4 me-1"></i>{{ $pendingTransactions }} transaction(s) are waiting on the tenant's settlement system. Reconciliation runs every five minutes; any still unresolved after a day are flagged in the audit log.
</div>
@endif

@if($archived)<div class="alert alert-secondary mb-4">This tenant is archived: no API, no portal. @if($can->can('tenants'))<form class="d-inline" method="post" action="{{ route('admin.tenants.restore', $tenant) }}">@csrf<button class="btn btn-sm btn-brand-outline ms-2">Restore (as suspended)</button></form>@endif</div>@endif

<div class="card mb-4">
    <div class="card-header d-flex justify-content-between"><span class="section-title mb-0 mt-0"><i class="ti ti-checklist"></i>Go-live checklist</span>@php $notReady = collect($checklist)->where('required', true)->where('ok', false)->count(); @endphp<span class="{{ $notReady ? 'badge-warning-soft' : 'badge-success-soft' }}">{{ $notReady ? $notReady . ' to do' : 'ready' }}</span></div>
    <ul class="list-group list-group-flush">
    @foreach($checklist as $c)<li class="list-group-item d-flex gap-2 align-items-start"><i class="ti {{ $c['ok'] ? 'ti-circle-check text-success' : ($c['required'] ? 'ti-circle-x text-danger' : 'ti-alert-triangle text-warning') }}" aria-hidden="true"></i><div><strong>{{ $c['label'] }}</strong>@unless($c['ok'])<div class="text-muted small">{{ $c['hint'] }}</div>@endunless</div></li>@endforeach
    </ul>
</div>

<div class="card mb-4">
    <div class="card-header"><span class="section-title mb-0 mt-0"><i class="ti ti-settings"></i>Settings</span></div>
    <div class="card-body">
        <form method="post" action="{{ route('admin.tenants.update', $tenant) }}">
            @csrf @method('PUT')
            <fieldset @disabled(! $can->can('tenants') || $archived)>
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
            <h6 class="fw-bold mt-4 mb-2">Offline codes built on a phone</h6>
            <p class="text-muted" style="font-size:12.5px">Lets the tenant's app enrol phones that build payment codes with no connection. Nothing is held when such a code is built, so the funds are checked when the call arrives: keep the caps low.</p>
            <div class="row g-3">
                <div class="col-12"><div class="form-check">
                    <input class="form-check-input" type="checkbox" name="device_codes_enabled" value="1" id="device_codes_enabled" @checked(old('device_codes_enabled', $tenant->setting('device_codes.enabled', false)))>
                    <label class="form-check-label" for="device_codes_enabled">Enabled for this tenant</label>
                </div></div>
                <div class="col-md-6"><label class="form-label" for="device_max_amount_minor">Largest payment (minor units, blank = platform default)</label><input id="device_max_amount_minor" name="device_max_amount_minor" type="number" min="1" class="form-control" value="{{ old('device_max_amount_minor', $tenant->setting('device_codes.max_amount_minor')) }}"></div>
                <div class="col-md-6"><label class="form-label" for="device_daily_limit_minor">Daily total per subscriber (minor units, blank = platform default)</label><input id="device_daily_limit_minor" name="device_daily_limit_minor" type="number" min="1" class="form-control" value="{{ old('device_daily_limit_minor', $tenant->setting('device_codes.daily_limit_minor')) }}"></div>
            </div>
            <div class="form-check mt-3">
                <input class="form-check-input" type="checkbox" name="bind_caller" value="1" id="bind_caller" @checked(old('bind_caller',$tenant->bind_caller))>
                <label class="form-check-label" for="bind_caller">Only accept a code from the payer's registered phone number</label>
            </div>
            <input type="hidden" name="direct_fields" value="1">
            <div class="row g-3 mt-1">
                <div class="col-12"><div class="form-check"><input class="form-check-input" type="checkbox" name="direct_enabled" value="1" id="direct_enabled" @checked(old('direct_enabled', $tenant->setting('device_codes.direct_enabled', false)))><label class="form-check-label" for="direct_enabled">“Pay any account” offline (45-digit codes) <span class="text-muted">: the bank can also change this in its portal</span></label></div></div>
                <div class="col-md-6"><label class="form-label" for="direct_max_amount_minor">Cap for those payments (minor units, blank = platform default)</label><input id="direct_max_amount_minor" name="direct_max_amount_minor" type="number" min="1" class="form-control" value="{{ old('direct_max_amount_minor', $tenant->setting('device_codes.direct_max_amount_minor')) }}"></div>
            </div>
            <div class="row g-3 mt-1">
                <div class="col-md-4"><label class="form-label" for="short_code">Shared-number short code</label><input id="short_code" name="short_code" class="form-control" maxlength="4" pattern="\d{4}" value="{{ old('short_code', $tenant->short_code) }}">@if($tenant->voice_mode === 'shared')<div class="form-text">Prefixed to every code. Cannot change while codes are open.</div>@else<div class="form-text">Used only on a shared number.</div>@endif</div>
                @if($tenant->environment !== 'live')<div class="col-md-8"><label class="form-label" for="confirm_live">Going live? Type <code>{{ $tenant->slug }}</code> to confirm</label><input id="confirm_live" name="confirm_live" class="form-control" autocomplete="off" placeholder="Only needed when switching to live"><div class="form-check mt-1"><input class="form-check-input" type="checkbox" name="override_checklist" value="1" id="override_checklist"><label class="form-check-label" for="override_checklist">Go live even if the checklist above is not complete</label></div></div>@endif
            </div>
            </fieldset>
            @if($can->can('tenants') && ! $archived)<button class="btn btn-brand mt-3">Save settings</button>@endif
        </form>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><span class="section-title mb-0 mt-0"><i class="ti ti-key"></i>API keys</span></div>
    <div class="table-responsive">
        <table class="table">
            <thead><tr><th>Name</th><th>Key</th><th>Last used</th><th>Limits</th><th class="text-end"></th></tr></thead>
            <tbody>
            @foreach ($keys as $key)
                <tr>
                    <td>{{ $key->name }}</td>
                    <td><code>opk_{{ $key->prefix }}_…</code> @if($key->revoked_at)<span class="badge-danger-soft ms-1">revoked</span>@endif</td>
                    <td class="text-muted">{{ $key->last_used_at?->diffForHumans() ?? 'never' }}@if($key->last_used_ip)<br><small>{{ $key->last_used_ip }}</small>@endif</td>
                    <td class="small">@if($key->expires_at)<span class="{{ $key->expires_at->isPast() ? 'badge-danger-soft' : 'badge-theme' }}">{{ $key->expires_at->isPast() ? 'expired' : 'expires ' . $key->expires_at->diffForHumans() }}</span><br>@endif @if($key->allowed_ips)only from {{ $key->allowed_ips }}@elseif(! $key->expires_at)<span class="text-muted">none</span>@endif</td>
                    <td class="text-end">@if(! $key->revoked_at && $can->can('tenants'))
                        <details class="d-inline-block text-start me-1"><summary class="btn btn-sm btn-brand-outline d-inline-block">Limits</summary>
                            <form method="post" action="{{ route('admin.keys.update', [$tenant, $key]) }}" class="card card-body mt-1 position-absolute" style="z-index:5;min-width:19rem">@csrf @method('PATCH')
                                <label class="form-label small" for="exp{{ $key->id }}">Expires in (days)</label><input id="exp{{ $key->id }}" name="expires_in_days" type="number" min="1" max="3650" class="form-control form-control-sm mb-2">
                                <label class="form-label small" for="ips{{ $key->id }}">Only from these IPs (comma-separated)</label><input id="ips{{ $key->id }}" name="allowed_ips" class="form-control form-control-sm mb-2" value="{{ $key->allowed_ips }}">
                                <div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="clear_expiry" value="1" id="ce{{ $key->id }}"><label class="form-check-label small" for="ce{{ $key->id }}">Remove the expiry</label></div>
                                <button class="btn btn-sm btn-brand">Save</button></form></details>
                        <form class="d-inline" method="post" action="{{ route('admin.keys.rotate', [$tenant, $key]) }}" data-confirm="A replacement is issued; the old key keeps working for 24 hours so the tenant can switch." data-confirm-label="Rotate key">@csrf<input type="hidden" name="grace_hours" value="24"><button class="btn btn-sm btn-brand-outline">Rotate</button></form>
                    @endif
                    @if(! $key->revoked_at && $can->can('tenants'))
                        <form class="d-inline" method="post" action="{{ route('admin.keys.destroy', [$tenant, $key]) }}" data-confirm="Anything using this key stops working at once." data-confirm-label="Revoke key">@csrf @method('DELETE')
                            <input type="hidden" name="confirm" value="1">
                            <button class="btn btn-sm btn-brand-outline" style="color:var(--danger)">Revoke</button></form>
                    @endif</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
    <div class="card-body border-top">
        <form method="post" action="{{ route('admin.keys.store', $tenant) }}" class="row g-2 align-items-end">
            @csrf
            <div class="col-md-3"><label class="form-label" for="key_name">New key name</label><input id="key_name" name="name" class="form-control" required maxlength="60"></div>
            <div class="col-md-2"><label class="form-label" for="key_days">Expires in (days)</label><input id="key_days" name="expires_in_days" type="number" min="1" max="3650" class="form-control" placeholder="never"></div>
            <div class="col-md-4"><label class="form-label" for="key_ips">Only from these IPs</label><input id="key_ips" name="allowed_ips" class="form-control" placeholder="optional, comma-separated"></div>
            <div class="col-auto"><button class="btn btn-brand" @disabled(! $can->can('tenants') || $archived)>Issue key</button></div>
        </form>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><span class="section-title mb-0 mt-0"><i class="ti ti-users"></i>Portal users</span></div>
    <div class="table-responsive">
        <table class="table">
            <thead><tr><th>Person</th><th>Role</th><th>Status</th><th>2FA</th><th>Last sign-in</th><th class="text-end"></th></tr></thead>
            <tbody>
            @forelse ($portalUsers as $u)
                <tr>
                    <td><div class="fw-semibold">{{ $u->name }}</div><div class="kv">{{ $u->email }}</div></td>
                    <td>@if($can->can('portal_users'))<form method="post" action="{{ route('admin.portal-users.role', [$tenant, $u->id]) }}" class="d-inline-flex gap-1">@csrf @method('PATCH')<select name="role" class="form-select form-select-sm" aria-label="Role of {{ $u->email }}">@foreach(\App\Models\TenantUser::ROLES as $r)<option value="{{ $r }}" @selected($u->role === $r)>{{ ucfirst($r) }}</option>@endforeach</select><button class="btn btn-sm btn-brand-outline">Set</button></form>@else<span class="text-capitalize">{{ $u->role }}</span>@endif</td>
                    <td>@if(! $u->hasAccepted())<span class="badge-warning-soft">invited</span>@elseif($u->is_active)<span class="badge-success-soft">active</span>@else<span class="badge-danger-soft">off</span>@endif</td>
                    <td>{{ $u->hasTwoFactor() ? 'on' : 'off' }}</td>
                    <td class="text-muted">{{ $u->last_login_at?->diffForHumans() ?? 'never' }}</td>
                    <td class="text-end">
                        @if($u->is_active && $u->hasAccepted() && $can->can('view_as'))<form class="d-inline-flex gap-1 align-items-center" method="post" action="{{ route('admin.portal-users.impersonate', [$tenant, $u->id]) }}">@csrf<input name="reason" class="form-control form-control-sm" style="width:11rem" placeholder="Reason (kept in their audit log)" required minlength="5" maxlength="300" aria-label="Reason for viewing as {{ $u->email }}"><button class="btn btn-sm btn-brand-outline" title="Look-only, 30 minutes">View as</button></form>@endif
                        <form class="d-inline" method="post" action="{{ route('admin.portal-users.reinvite', [$tenant, $u->id]) }}">@csrf<button class="btn btn-sm btn-brand-outline">New access link</button></form>
                        @if($u->hasTwoFactor())<form class="d-inline" method="post" action="{{ route('admin.portal-users.reset-2fa', [$tenant, $u->id]) }}" data-confirm="They will set up their authenticator again at next sign-in." data-confirm-label="Reset 2FA">@csrf<button class="btn btn-sm btn-brand-outline">Reset 2FA</button></form>@endif
                        @if($can->can('portal_users'))<form class="d-inline" method="post" action="{{ route('admin.portal-users.toggle', [$tenant, $u->id]) }}">@csrf @method('PATCH')<button class="btn btn-sm btn-brand-outline">{{ $u->is_active ? 'Switch off' : 'Restore' }}</button></form>
                        <form class="d-inline" method="post" action="{{ route('admin.portal-users.destroy', [$tenant, $u->id]) }}" data-confirm="{{ $u->email }} is removed from this tenant's portal." data-confirm-label="Remove">@csrf @method('DELETE')<button class="btn btn-sm btn-danger">Remove</button></form>@endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="text-muted">No one can sign in to this tenant's portal yet. Invite the first owner below.</td></tr>
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
    @if ($tenant->voice_mode === 'shared')<div class="card-body pb-0"><p class="text-muted mb-0">This tenant uses the shared pool. Manage the pool's numbers on the <a href="{{ route('admin.voice.index', ['owner' => 'pool']) }}">Voice numbers</a> page.</p></div>@endif
    <div class="table-responsive">
        <table class="table">
            <thead><tr><th>Number</th><th>Provider</th><th>Active</th><th class="text-end"></th></tr></thead>
            <tbody>
            @forelse ($numbers as $number)
                <tr>
                    <td>@if($can->can('tenants'))<form method="post" action="{{ route('admin.voice.update', $number) }}" class="d-inline-flex gap-1" data-confirm="Calls to {{ $number->number }} stop being handled. Point the provider at the new number first." data-confirm-label="Change number">@csrf @method('PUT')<input name="number" value="{{ $number->number }}" class="form-control form-control-sm" style="width:12rem" aria-label="Number" required pattern="\+?\d{7,15}"><button class="btn btn-sm btn-brand-outline">Change</button></form>@else{{ $number->number }}@endif</td><td>{{ $number->provider }}</td>
                    <td><span class="{{ $number->active ? 'badge-success-soft' : 'badge-danger-soft' }}">{{ $number->active ? 'yes' : 'no' }}</span></td>
                    <td class="text-end">
                        <form class="d-inline" method="post" action="{{ route('admin.numbers.toggle', [$tenant, $number]) }}">@csrf @method('PATCH')<button class="btn btn-sm btn-brand-outline">{{ $number->active ? 'Disable' : 'Enable' }}</button></form>
                        <form class="d-inline" method="post" action="{{ route('admin.numbers.rotate', [$tenant, $number]) }}">@csrf<button class="btn btn-sm btn-brand-outline">Rotate token</button></form>
                        <form class="d-inline-flex gap-1" method="post" action="{{ route('admin.numbers.reassign', [$tenant, $number]) }}" data-confirm="Calls to {{ $number->number }} will be handled for the other owner from now on." data-confirm-label="Move number">@csrf<select name="to" class="form-select form-select-sm" aria-label="Move {{ $number->number }} to"><option value="pool">Shared pool</option>@foreach($otherTenants as $id => $n)<option value="{{ $id }}">{{ $n }}</option>@endforeach</select><button class="btn btn-sm btn-brand-outline">Move</button></form>
                        <form class="d-inline" method="post" action="{{ route('admin.numbers.destroy', [$tenant, $number]) }}" data-confirm="{{ $number->number }} is deleted and its callback URL stops working." data-confirm-label="Delete number">@csrf @method('DELETE')<button class="btn btn-sm btn-danger">Delete</button></form>
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

@if($can->can('tenants'))
<div class="card mb-4">
    <div class="card-header"><span class="section-title mb-0 mt-0"><i class="ti ti-shield-half"></i>Emergency and lifecycle</span></div>
    <div class="card-body">
        <div class="row g-4">
            <div class="col-lg-6">
                <h6 class="fw-bold">All at once</h6>
                <form class="d-inline" method="post" action="{{ route('admin.tenants.bulk-users', $tenant) }}" data-confirm="Nobody at this tenant can sign in to the portal." data-confirm-label="Switch all off">@csrf<input type="hidden" name="action" value="off"><button class="btn btn-sm btn-brand-outline">Switch off every portal user</button></form>
                <form class="d-inline" method="post" action="{{ route('admin.tenants.bulk-users', $tenant) }}">@csrf<input type="hidden" name="action" value="on"><button class="btn btn-sm btn-brand-outline">Switch them all on</button></form>
                <form class="mt-2" method="post" action="{{ route('admin.keys.revoke-all', $tenant) }}">@csrf
                    <div class="input-group input-group-sm" style="max-width:26rem"><input name="confirm_slug" class="form-control" placeholder="Type {{ $tenant->slug }} to revoke every API key" aria-label="Type the identifier to revoke every key" autocomplete="off"><button class="btn btn-danger">Revoke all keys</button></div></form>
            </div>
            <div class="col-lg-6">
                @unless($archived)
                <h6 class="fw-bold">Archive</h6>
                <p class="text-muted small mb-2">Stops the API and the portal, revokes every key, switches the numbers off. History is kept. It can be restored (as suspended).</p>
                <form method="post" action="{{ route('admin.tenants.archive', $tenant) }}">@csrf<div class="input-group input-group-sm" style="max-width:26rem"><input name="confirm_slug" class="form-control" placeholder="Type {{ $tenant->slug }} to archive" aria-label="Type the identifier to archive" autocomplete="off"><button class="btn btn-danger">Archive</button></div></form>
                @else
                <h6 class="fw-bold">Delete</h6>
                <p class="text-muted small mb-2">{{ $hasPayments ? 'This tenant has payment history, which is always kept. It cannot be deleted.' : 'No payments were ever made, so it can be deleted for good.' }}</p>
                @unless($hasPayments)<form method="post" action="{{ route('admin.tenants.destroy', $tenant) }}">@csrf @method('DELETE')<div class="input-group input-group-sm" style="max-width:26rem"><input name="confirm_slug" class="form-control" placeholder="Type {{ $tenant->slug }} to delete" aria-label="Type the identifier to delete" autocomplete="off"><button class="btn btn-danger">Delete</button></div></form>@endunless
                @endunless
            </div>
        </div>
    </div>
</div>
@endif
@endsection
