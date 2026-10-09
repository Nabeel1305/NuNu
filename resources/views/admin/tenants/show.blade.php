@extends('layouts.admin')
@section('title', $tenant->name)
@section('content')
<p><a href="{{ route('admin.tenants.index') }}">← All tenants</a></p>
<h1>{{ $tenant->name }} <span class="muted">{{ $tenant->slug }}</span></h1>

@if ($pendingTransactions > 0)
  <div class="flash warn">{{ $pendingTransactions }} transaction(s) are waiting on the tenant's settlement system. Reconciliation runs every five minutes; any still unresolved after a day are flagged in the audit log.</div>
@endif

<section>
  <h2>Settings</h2>
  <form method="post" action="{{ route('admin.tenants.update', $tenant) }}">
    @csrf @method('PUT')
    <div class="row">
      <div><label for="status">Status</label>
        <select id="status" name="status">@foreach (['active','suspended'] as $v)<option value="{{ $v }}" @selected(old('status',$tenant->status)===$v)>{{ $v }}</option>@endforeach</select></div>
      <div><label for="environment">Environment</label>
        <select id="environment" name="environment">@foreach (['sandbox','live'] as $v)<option value="{{ $v }}" @selected(old('environment',$tenant->environment)===$v)>{{ $v }}</option>@endforeach</select></div>
      <div><label for="settlement_adapter">Settlement adapter</label>
        <select id="settlement_adapter" name="settlement_adapter">@foreach ($adapters as $v)<option value="{{ $v }}" @selected(old('settlement_adapter',$tenant->settlement_adapter)===$v)>{{ $v }}</option>@endforeach</select></div>
      <div><label for="voice_mode">Voice number</label>
        <select id="voice_mode" name="voice_mode">@foreach (['own','shared'] as $v)<option value="{{ $v }}" @selected(old('voice_mode',$tenant->voice_mode)===$v)>{{ $v }}</option>@endforeach</select></div>
      <div><label for="code_ttl_minutes">Code lifetime (minutes)</label><input id="code_ttl_minutes" name="code_ttl_minutes" type="number" min="1" max="60" value="{{ old('code_ttl_minutes',$tenant->code_ttl_minutes) }}"></div>
      <div><label for="max_amount_minor">Max amount (minor units, optional)</label><input id="max_amount_minor" name="max_amount_minor" type="number" min="1" value="{{ old('max_amount_minor',$tenant->setting('max_amount_minor')) }}"></div>
    </div>
    <p><label style="display:inline"><input type="checkbox" name="bind_caller" value="1" @checked(old('bind_caller',$tenant->bind_caller))> Only accept a code from the payer's registered phone number</label></p>
    @if ($tenant->voice_mode === 'shared')<p class="muted">Shared-pool short code: <strong>{{ $tenant->short_code }}</strong> (prefixed to every code).</p>@endif
    <button class="primary">Save settings</button>
  </form>
</section>

<section>
  <h2>API keys</h2>
  <table>
    <thead><tr><th>Name</th><th>Key</th><th>Last used</th><th></th></tr></thead>
    <tbody>
    @foreach ($keys as $key)
      <tr>
        <td>{{ $key->name }}</td>
        <td><code>opk_{{ $key->prefix }}_…</code> @if($key->revoked_at)<span class="bad">revoked</span>@endif</td>
        <td class="muted">{{ $key->last_used_at?->diffForHumans() ?? 'never' }}</td>
        <td>@unless($key->revoked_at)
          <form class="inline" method="post" action="{{ route('admin.keys.destroy', [$tenant, $key]) }}">@csrf @method('DELETE')
            <label style="display:inline" class="muted"><input type="checkbox" name="confirm" value="1" required> anything using it stops at once</label>
            <button class="danger">Revoke</button></form>
        @endunless</td>
      </tr>
    @endforeach
    </tbody>
  </table>
  <form method="post" action="{{ route('admin.keys.store', $tenant) }}" class="row" style="margin-top:12px;align-items:end">
    @csrf
    <div><label for="key_name">New key name</label><input id="key_name" name="name" required maxlength="60"></div>
    <div><button class="primary">Issue key</button></div>
  </form>
</section>

<section>
  <h2>Voice numbers</h2>
  @if ($tenant->voice_mode === 'shared')<p class="muted">This tenant uses the shared pool. Add shared numbers from the command line: <code>voice-number:add</code> with no tenant.</p>@endif
  <table>
    <thead><tr><th>Number</th><th>Provider</th><th>Active</th><th></th></tr></thead>
    <tbody>
    @forelse ($numbers as $number)
      <tr>
        <td>{{ $number->number }}</td><td>{{ $number->provider }}</td>
        <td class="{{ $number->active ? 'good' : 'bad' }}">{{ $number->active ? 'yes' : 'no' }}</td>
        <td>
          <form class="inline" method="post" action="{{ route('admin.numbers.toggle', [$tenant, $number]) }}">@csrf @method('PATCH')<button>{{ $number->active ? 'Disable' : 'Enable' }}</button></form>
          <form class="inline" method="post" action="{{ route('admin.numbers.rotate', [$tenant, $number]) }}">@csrf<button>Rotate token</button></form>
        </td>
      </tr>
    @empty
      <tr><td colspan="4" class="muted">No numbers.</td></tr>
    @endforelse
    </tbody>
  </table>
  <form method="post" action="{{ route('admin.numbers.store', $tenant) }}" class="row" style="margin-top:12px;align-items:end">
    @csrf
    <div><label for="number">Add number (E.164)</label><input id="number" name="number" placeholder="+2347000000000" required></div>
    <div><button class="primary">Add number</button></div>
  </form>
  <p class="muted">Adding a number shows the callback URL once. Paste it into the provider's dashboard.</p>
</section>

<section>
  <h2>Recent webhook deliveries</h2>
  <table>
    <thead><tr><th>Event</th><th>Status</th><th>Attempts</th><th>Last result</th><th>When</th></tr></thead>
    <tbody>
    @forelse ($deliveries as $d)
      <tr>
        <td>{{ $d->event_type }}</td>
        <td class="{{ ['delivered'=>'good','failed'=>'bad'][$d->status] ?? 'warn' }}">{{ $d->status }}</td>
        <td>{{ $d->attempts }}</td>
        <td class="muted">{{ $d->last_status_code ?? '' }} {{ $d->last_error }}</td>
        <td class="muted">{{ $d->created_at->diffForHumans() }}</td>
      </tr>
    @empty
      <tr><td colspan="5" class="muted">None yet.</td></tr>
    @endforelse
    </tbody>
  </table>
</section>

<section>
  <h2>Audit log</h2>
  <form method="post" action="{{ route('admin.tenants.verify-audit', $tenant) }}" style="margin-bottom:12px">@csrf <button>Verify chain integrity</button></form>
  <table>
    <thead><tr><th>#</th><th>Event</th><th>Description</th><th>When</th></tr></thead>
    <tbody>
    @forelse ($audit as $row)
      <tr><td class="muted">{{ $row->id }}</td><td>{{ $row->event_type }}</td><td>{{ $row->description }}</td><td class="muted">{{ $row->created_at->diffForHumans() }}</td></tr>
    @empty
      <tr><td colspan="4" class="muted">Nothing recorded yet.</td></tr>
    @endforelse
    </tbody>
  </table>
</section>
@endsection
