@extends('layouts.admin')
@section('title', 'Dashboard')
@section('content')
<div class="mb-4"><h4 class="fw-bold mb-1"><i class="ti ti-layout-dashboard me-2" style="color:var(--brand-accent);"></i>Platform dashboard</h4><p class="text-muted mb-0">All tenants together.</p></div>
<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3"><a class="text-decoration-none" href="{{ route('admin.payments.index', ['stuck' => 1]) }}"><div class="card h-100"><div class="card-body"><div class="text-muted small">Stuck pending</div><div class="fs-3 fw-bold {{ $stuck ? 'text-warning' : '' }}">{{ $stuck }}</div></div></div></a></div>
    <div class="col-6 col-lg-3"><a class="text-decoration-none" href="{{ route('admin.deliveries.index', ['status' => 'failed']) }}"><div class="card h-100"><div class="card-body"><div class="text-muted small">Failed webhooks (24h)</div><div class="fs-3 fw-bold {{ $failedDeliveries ? 'text-danger' : '' }}">{{ $failedDeliveries }}</div></div></div></a></div>
    <div class="col-6 col-lg-3"><div class="card h-100"><div class="card-body"><div class="text-muted small">Webhook backlog</div><div class="fs-3 fw-bold">{{ $backlog }}</div></div></div></div>
    <div class="col-6 col-lg-3"><a class="text-decoration-none" href="{{ route('admin.health') }}"><div class="card h-100"><div class="card-body"><div class="text-muted small">Failed jobs</div><div class="fs-3 fw-bold {{ $failedJobs ? 'text-danger' : '' }}">{{ $failedJobs }}</div></div></div></a></div>
</div>
<div class="card mb-4"><div class="card-header"><span class="section-title mb-0 mt-0">Payments</span></div><div class="table-responsive"><table class="table mb-0">
<thead><tr><th>Period</th><th>Payments</th><th>Settled volume</th><th>Success rate</th><th>Failed</th><th>Pending</th><th>Made offline</th></tr></thead><tbody>
@foreach($stats as $label => $s)<tr><td>Last {{ $label }}</td><td>{{ number_format($s['total']) }}</td><td>{{ \App\Support\Money::format($s['volume'], 'NGN') }}</td>
<td>@if($s['rate'] === null)·@else<div class="d-flex align-items-center gap-2"><div style="width:90px;height:8px;background:var(--bs-secondary-bg,#e5e7eb);border-radius:4px;overflow:hidden"><div style="width:{{ $s['rate'] }}%;height:100%;background:{{ $s['rate'] >= 95 ? '#16a34a' : ($s['rate'] >= 80 ? '#d97706' : '#dc2626') }}"></div></div>{{ $s['rate'] }}%</div>@endif</td>
<td>{{ $s['failed'] }}</td><td>{{ $s['pending'] }}</td><td>{{ $s['offline'] }}@if($s['offline_share'] !== null) <small class="text-muted">({{ $s['offline_share'] }}%)</small>@endif</td></tr>@endforeach</tbody></table></div></div>
<div class="row g-4">
    <div class="col-lg-8"><div class="card h-100"><div class="card-header"><span class="section-title mb-0 mt-0">Busiest tenants, last 30 days</span></div><div class="table-responsive"><table class="table mb-0"><thead><tr><th>Tenant</th><th>Payments</th><th>Settled volume</th><th>Failed</th></tr></thead><tbody>
    @forelse($top as $t)<tr><td><a href="{{ route('admin.tenants.show', $t->id) }}">{{ $t->name }}</a></td><td>{{ number_format($t->total) }}</td><td>{{ \App\Support\Money::format($t->volume, 'NGN') }}</td><td>{{ $t->failed }}</td></tr>@empty<tr><td colspan="4" class="text-muted">No payments yet.</td></tr>@endforelse</tbody></table></div></div></div>
    <div class="col-lg-4"><div class="card h-100"><div class="card-header"><span class="section-title mb-0 mt-0">Platform</span></div><div class="card-body"><dl class="dl">
        @foreach(['active','suspended','archived'] as $s)<dt>{{ ucfirst($s) }} tenants</dt><dd>{{ $tenantCounts[$s] ?? 0 }}</dd>@endforeach
        <dt>Phones enrolled</dt><dd><a href="{{ route('admin.devices.index', ['status' => 'active']) }}">{{ $devices }}</a></dd>
    </dl></div></div></div>
</div>
@endsection
