@extends('layouts.admin')
@section('title', 'Webhook deliveries')
@section('content')
@php $can = auth('admin')->user(); @endphp
<div class="mb-4"><h4 class="fw-bold mb-1"><i class="ti ti-send me-2" style="color:var(--brand-accent);"></i>Webhook deliveries</h4><p class="text-muted mb-0">Finished deliveries are kept 30 days. <a href="{{ route('admin.endpoints.index') }}">Endpoints</a></p></div>
<form class="row g-2 mb-3" method="get">
<div class="col-md-3"><select name="tenant" class="form-select form-select-sm"><option value="">Any tenant</option>@foreach($tenants as $id => $n)<option value="{{ $id }}" @selected(request('tenant') == $id)>{{ $n }}</option>@endforeach</select></div>
<div class="col-md-2"><select name="status" class="form-select form-select-sm"><option value="">Any status</option>@foreach(['pending','delivered','failed'] as $s)<option @selected(request('status') === $s)>{{ $s }}</option>@endforeach</select></div>
<div class="col-md-3"><select name="event" class="form-select form-select-sm"><option value="">Any event</option>@foreach($events as $e)<option @selected(request('event') === $e)>{{ $e }}</option>@endforeach</select></div><div class="col-auto"><button class="btn btn-sm btn-brand">Filter</button></div></form>
<div class="card"><div class="table-responsive"><table class="table mb-0"><thead><tr><th>When</th><th>Tenant</th><th>Event</th><th>Status</th><th>Attempts</th><th>Last result</th><th></th></tr></thead><tbody>
@forelse($rows as $d)<tr><td>{{ $d->created_at->format('d M H:i') }}</td><td>{{ $d->tenant }}</td><td><a href="{{ route('admin.deliveries.show', $d->id) }}">{{ $d->event_type }}</a></td>
<td><span class="{{ ['delivered' => 'badge-success-soft', 'failed' => 'badge-danger-soft'][$d->status] ?? 'badge-warning-soft' }}">{{ $d->status }}</span></td><td>{{ $d->attempts }}</td><td>{{ $d->last_status_code }} {{ $d->last_error }}</td>
<td class="text-end">@if($d->status !== 'delivered' && $can->can('webhooks'))<form method="post" action="{{ route('admin.deliveries.retry', $d->id) }}">@csrf<button class="btn btn-sm btn-brand-outline">Retry</button></form>@endif</td></tr>
@empty<tr><td colspan="7" class="text-muted">Nothing matches.</td></tr>@endforelse</tbody></table></div></div>
<div class="mt-3">{{ $rows->links() }}</div>
@endsection
