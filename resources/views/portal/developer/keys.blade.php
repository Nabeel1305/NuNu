@extends('layouts.portal')
@section('title', 'API keys')
@section('content')
@php $canManage = auth('tenant')->user()->can('manage_keys'); @endphp
<div class="mb-4">
    <h4 class="fw-bold mb-1"><i class="ti ti-key me-2" style="color:var(--brand-accent);"></i>API keys</h4>
    <p class="text-muted mb-0">Keys your servers use to call <code>{{ url('/api/v1') }}</code> as <code>Authorization: Bearer &lt;key&gt;</code>. A key is shown once, when it is created.</p>
</div>

<div class="card mb-4">
    <div class="table-responsive"><table class="table">
        <thead><tr><th>Name</th><th>Key</th><th>Created</th><th>Last used</th><th>Status</th><th class="text-end"></th></tr></thead>
        <tbody>
        @forelse($keys as $key)
            <tr>
                <td class="fw-semibold">{{ $key->name }}</td>
                <td><code>opk_{{ $key->prefix }}_…</code></td>
                <td class="text-muted">{{ $key->created_at?->diffForHumans() }}</td>
                <td class="text-muted">{{ $key->last_used_at?->diffForHumans() ?? 'never' }}</td>
                <td>@if($key->revoked_at)<span class="badge-danger-soft">revoked</span>@else<span class="badge-success-soft">active</span>@endif</td>
                <td class="text-end">
                    @if($canManage && ! $key->revoked_at)
                    <form class="d-inline" method="post" action="{{ route('portal.keys.destroy', $key->id) }}" data-confirm="Anything using this key stops working immediately. This cannot be undone." data-confirm-label="Revoke key">@csrf @method('DELETE')
                        <button class="btn btn-sm btn-brand-outline" style="color:var(--danger)">Revoke</button></form>
                    @endif
                </td>
            </tr>
        @empty
            <tr><td colspan="6" class="text-muted">No keys yet.</td></tr>
        @endforelse
        </tbody>
    </table></div>
</div>

@if($canManage)
<div class="card" style="max-width:640px">
    <div class="card-header"><span class="section-title mb-0 mt-0">Create a key</span></div>
    <div class="card-body">
        <form method="post" action="{{ route('portal.keys.store') }}" class="row g-2 align-items-end">@csrf
            <div class="col-sm-8"><label class="form-label" for="name">Name (what will use it)</label><input id="name" name="name" class="form-control" required maxlength="60" placeholder="e.g. Production core banking"></div>
            <div class="col-auto"><button class="btn btn-brand">Create key</button></div>
        </form>
        <p class="text-muted mt-3 mb-0" style="font-size:12.5px">Tip: use one key per system, so you can revoke one without stopping the others.</p>
    </div>
</div>
@endif
@endsection
