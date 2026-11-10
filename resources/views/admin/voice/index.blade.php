@extends('layouts.admin')
@section('title', 'Voice numbers')
@section('content')
@php $can = auth('admin')->user()->can('tenants'); @endphp
<div class="mb-4">
    <h4 class="fw-bold mb-1"><i class="ti ti-phone-call me-2" style="color:var(--brand-accent);"></i>Voice numbers</h4>
    <p class="text-muted mb-0">The numbers payers ring. A tenant in <strong>own</strong> mode uses its own number; one in <strong>shared</strong> mode uses a number from the shared pool (and its codes start with its short code).</p>
</div>
@if($sharedWithoutNumber->isNotEmpty())<div class="alert alert-warning">The shared pool has no active number, but these tenants are in shared mode: <strong>{{ $sharedWithoutNumber->implode(', ') }}</strong>. Their payers get no number to ring.</div>@endif
<form class="row g-2 mb-3" method="get"><div class="col-md-3"><select name="owner" class="form-select form-select-sm"><option value="">Everyone</option><option value="pool" @selected(request('owner') === 'pool')>Shared pool</option>@foreach($tenants as $t)<option value="{{ $t->id }}" @selected(request('owner') == $t->id)>{{ $t->name }}</option>@endforeach</select></div><div class="col-auto"><button class="btn btn-sm btn-brand">Filter</button></div></form>
<div class="card mb-4"><div class="table-responsive"><table class="table mb-0">
<thead><tr><th>Number</th><th>Belongs to</th><th>Status</th><th class="text-end"></th></tr></thead><tbody>
@forelse($rows as $r)
<tr>
    <td>@if($can)<form method="post" action="{{ route('admin.voice.update', $r->id) }}" class="d-inline-flex gap-1" data-confirm="Calls to {{ $r->number }} stop being handled. Point the provider at the new number first." data-confirm-label="Change number">@csrf @method('PUT')<input name="number" value="{{ $r->number }}" class="form-control form-control-sm" style="width:12rem" aria-label="Number" required pattern="\+?\d{7,15}"><button class="btn btn-sm btn-brand-outline">Change</button></form>@else<code>{{ $r->number }}</code>@endif</td>
    <td>@if($r->tenant_id)<a href="{{ route('admin.tenants.show', $r->tenant_id) }}">{{ $r->tenant }}</a> <small class="text-muted">({{ $r->voice_mode }})</small>@else<span class="badge-info-soft">Shared pool</span>@endif</td>
    <td><span class="{{ $r->active ? 'badge-success-soft' : 'badge-danger-soft' }}">{{ $r->active ? 'active' : 'off' }}</span></td>
    <td class="text-end">@if($can)
        <form class="d-inline" method="post" action="{{ route('admin.voice.toggle', $r->id) }}">@csrf @method('PATCH')<button class="btn btn-sm btn-brand-outline">{{ $r->active ? 'Disable' : 'Enable' }}</button></form>
        <form class="d-inline" method="post" action="{{ route('admin.voice.rotate', $r->id) }}" data-confirm="The old callback URL stops working at once." data-confirm-label="Rotate token">@csrf<button class="btn btn-sm btn-brand-outline">New callback URL</button></form>
        <form class="d-inline-flex gap-1" method="post" action="{{ route('admin.voice.reassign', $r->id) }}" data-confirm="Calls to {{ $r->number }} will be handled for the new owner." data-confirm-label="Move number">@csrf<select name="to" class="form-select form-select-sm" aria-label="Move {{ $r->number }} to"><option value="pool" @selected(! $r->tenant_id)>Shared pool</option>@foreach($tenants as $t)<option value="{{ $t->id }}" @selected($r->tenant_id == $t->id)>{{ $t->name }}</option>@endforeach</select><button class="btn btn-sm btn-brand-outline">Move</button></form>
        <form class="d-inline" method="post" action="{{ route('admin.voice.destroy', $r->id) }}" data-confirm="{{ $r->number }} is deleted and its callback URL stops working." data-confirm-label="Delete number">@csrf @method('DELETE')<button class="btn btn-sm btn-danger">Delete</button></form>
    @endif</td>
</tr>
@empty<tr><td colspan="4" class="text-muted">No numbers yet.</td></tr>@endforelse
</tbody></table></div>
@if($can)
<div class="card-body border-top">
    <form method="post" action="{{ route('admin.voice.store') }}" class="row g-2 align-items-end">@csrf
        <div class="col-md-4"><label class="form-label" for="vn_number">Add a number (E.164)</label><input id="vn_number" name="number" class="form-control" placeholder="+2342017001724" required pattern="\+?\d{7,15}"></div>
        <div class="col-md-4"><label class="form-label" for="vn_owner">For</label><select id="vn_owner" name="owner" class="form-select"><option value="pool">Shared pool</option>@foreach($tenants as $t)<option value="{{ $t->id }}">{{ $t->name }} ({{ $t->voice_mode }})</option>@endforeach</select></div>
        <div class="col-auto"><button class="btn btn-brand">Add number</button></div>
    </form>
    <p class="text-muted mt-3 mb-0">Adding a number shows its callback URL once. Paste it into the provider's dashboard. Changing a number keeps its callback URL, so only the provider's routing has to follow.</p>
</div>
@endif
</div>
@endsection
