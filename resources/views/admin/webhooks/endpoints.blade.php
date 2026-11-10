@extends('layouts.admin')
@section('title', 'Webhook endpoints')
@section('content')
@php $can = auth('admin')->user(); @endphp
<div class="mb-4"><h4 class="fw-bold mb-1"><i class="ti ti-webhook me-2" style="color:var(--brand-accent);"></i>Webhook endpoints</h4><p class="text-muted mb-0">Where each tenant receives events. <a href="{{ route('admin.deliveries.index') }}">All deliveries</a></p></div>
<form class="row g-2 mb-3" method="get"><div class="col-md-3"><select name="tenant" class="form-select form-select-sm"><option value="">Any tenant</option>@foreach($tenants as $id => $n)<option value="{{ $id }}" @selected(request('tenant') == $id)>{{ $n }}</option>@endforeach</select></div><div class="col-auto"><button class="btn btn-sm btn-brand">Filter</button></div></form>
<div class="card"><div class="table-responsive"><table class="table mb-0"><thead><tr><th>Tenant</th><th>URL</th><th>Events</th><th>Last 24h</th><th>Status</th><th></th></tr></thead><tbody>
@forelse($rows as $r)<tr><td><a href="{{ route('admin.tenants.show', $r->tenant_id) }}">{{ $r->tenant }}</a></td><td style="word-break:break-all">{{ $r->url }}</td><td>{{ $r->events ? implode(', ', json_decode($r->events, true) ?: []) : 'all' }}</td>
<td><span class="badge-success-soft">{{ $r->ok_24h }} ok</span> @if($r->failed_24h)<a class="badge-danger-soft text-decoration-none" href="{{ route('admin.deliveries.index', ['tenant' => $r->tenant_id, 'status' => 'failed']) }}">{{ $r->failed_24h }} failed</a>@endif</td>
<td><span class="{{ $r->active ? 'badge-success-soft' : 'badge-theme' }}">{{ $r->active ? 'active' : 'paused' }}</span></td>
<td class="text-end">@if($can->can('webhooks'))
<form class="d-inline" method="post" action="{{ route('admin.endpoints.test', $r->id) }}">@csrf<button class="btn btn-sm btn-brand-outline">Send test</button></form>
<form class="d-inline" method="post" action="{{ route('admin.endpoints.toggle', $r->id) }}">@csrf @method('PATCH')<button class="btn btn-sm btn-brand-outline">{{ $r->active ? 'Pause' : 'Resume' }}</button></form>
<form class="d-inline" method="post" action="{{ route('admin.endpoints.rotate', $r->id) }}" data-confirm="The old secret stops working immediately; the tenant must switch to the new one." data-confirm-label="Rotate">@csrf<button class="btn btn-sm btn-brand-outline">New secret</button></form>@endif</td></tr>
@empty<tr><td colspan="6" class="text-muted">No endpoints.</td></tr>@endforelse</tbody></table></div></div>
@endsection
