@extends('layouts.admin')
@section('title', 'Phones')
@section('content')
@php $can = auth('admin')->user(); @endphp
<div class="mb-4 d-flex justify-content-between align-items-start flex-wrap gap-2">
    <div><h4 class="fw-bold mb-1"><i class="ti ti-device-mobile me-2" style="color:var(--brand-accent);"></i>Phones</h4>
    <p class="text-muted mb-0">Phones enrolled to pay offline. Switching one off stops its codes at once. (If the tenant's app enrols it again, it comes back: the tenant must tie enrolment to a signed-in customer.)</p></div>
    @if($can->can('reports'))<a class="btn btn-brand-outline btn-sm" href="{{ route('admin.devices.export', request()->query()) }}"><i class="ti ti-download me-1"></i>Export CSV</a>@endif
</div>
<form class="row g-2 mb-3" method="get"><div class="col-md-3"><input name="q" class="form-control form-control-sm" placeholder="Short id, payer, phone" value="{{ request('q') }}"></div>
<div class="col-md-3"><select name="tenant" class="form-select form-select-sm"><option value="">Any tenant</option>@foreach($tenants as $id => $n)<option value="{{ $id }}" @selected(request('tenant') == $id)>{{ $n }}</option>@endforeach</select></div>
<div class="col-md-2"><select name="status" class="form-select form-select-sm"><option value="">Any status</option><option value="active" @selected(request('status') === 'active')>active</option><option value="revoked" @selected(request('status') === 'revoked')>revoked</option></select></div>
<div class="col-auto form-check align-self-center"><input class="form-check-input" type="checkbox" name="idle" value="1" id="idle" @checked(request()->boolean('idle'))><label class="form-check-label" for="idle">Unused for 30 days</label></div><div class="col-auto"><button class="btn btn-sm btn-brand">Filter</button></div></form>
<div class="card"><div class="table-responsive"><table class="table mb-0"><thead><tr><th>Short id</th><th>Payer</th><th>Tenant</th><th>Status</th><th>Counter</th><th>Last used</th><th>Enrolled</th><th></th></tr></thead><tbody>
@forelse($rows as $r)<tr><td><code>{{ $r->short_id }}</code></td><td>{{ $r->subscriber }}<br><small class="text-muted">{{ $r->phone }}</small></td><td><a href="{{ route('admin.tenants.show', $r->tenant_id) }}">{{ $r->tenant }}</a></td>
<td><span class="{{ $r->status === 'active' ? 'badge-success-soft' : 'badge-danger-soft' }}">{{ $r->status }}</span> <small class="text-muted">key v{{ $r->key_version }}</small></td><td>{{ $r->last_counter }}</td>
<td>{{ $r->last_used_at ? \Illuminate\Support\Carbon::parse($r->last_used_at)->diffForHumans() : 'never' }}</td><td>{{ \Illuminate\Support\Carbon::parse($r->created_at)->format('d M Y') }}</td>
<td class="text-end">@if($r->status === 'active' && $can->can('devices'))<form method="post" action="{{ route('admin.devices.revoke', $r->id) }}" data-confirm="Its offline codes stop working at once." data-confirm-label="Switch off">@csrf<button class="btn btn-sm btn-danger">Switch off</button></form>@endif</td></tr>
@empty<tr><td colspan="8" class="text-muted">No phones.</td></tr>@endforelse</tbody></table></div></div>
<div class="mt-3">{{ $rows->links() }}</div>
@endsection
