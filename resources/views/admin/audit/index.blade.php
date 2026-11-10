@extends('layouts.admin')
@section('title', 'Audit log')
@section('content')
<div class="mb-4 d-flex justify-content-between align-items-start flex-wrap gap-2">
    <div>
        <h4 class="fw-bold mb-1"><i class="ti ti-history me-2" style="color:var(--brand-accent);"></i>Platform audit log</h4>
        <p class="text-muted mb-0">What operators did on this console, and every sign-in attempt. Nothing here can be edited.</p>
    </div>
    <a class="btn btn-brand-outline btn-sm" href="{{ route('admin.audit.export', request()->query()) }}"><i class="ti ti-download me-1"></i>Export CSV</a>
</div>
<form class="row g-2 mb-3" method="get">
    <div class="col-md-3"><input name="operator" class="form-control form-control-sm" placeholder="Operator email" value="{{ request('operator') }}"></div>
    <div class="col-md-2"><input name="event" class="form-control form-control-sm" placeholder="Event, e.g. admin.login" value="{{ request('event') }}"></div>
    <div class="col-md-2"><select name="tenant" class="form-select form-select-sm"><option value="">Any tenant</option>@foreach($tenants as $id => $n)<option value="{{ $id }}" @selected(request('tenant') == $id)>{{ $n }}</option>@endforeach</select></div>
    <div class="col-md-2"><select name="outcome" class="form-select form-select-sm"><option value="">Any outcome</option>@foreach(['ok','failed','denied'] as $o)<option @selected(request('outcome') === $o)>{{ $o }}</option>@endforeach</select></div>
    <div class="col-md-2 d-flex gap-1"><input name="from" type="date" class="form-control form-control-sm" value="{{ request('from') }}" aria-label="From"><input name="to" type="date" class="form-control form-control-sm" value="{{ request('to') }}" aria-label="To"></div>
    <div class="col-auto"><button class="btn btn-sm btn-brand">Filter</button></div>
</form>
<div class="card"><div class="table-responsive"><table class="table mb-0">
    <thead><tr><th>When</th><th>Operator</th><th>Event</th><th>What</th><th>Outcome</th><th>From</th></tr></thead>
    <tbody>
    @forelse($rows as $r)
        <tr>
            <td title="{{ $r->created_at }}">{{ $r->created_at->format('d M H:i') }}</td>
            <td>{{ $r->admin_email ?? 'unknown' }}</td>
            <td><code>{{ $r->event }}</code></td>
            <td>{{ $r->description }}@if($r->tenant_id) <small class="text-muted">tenant #{{ $r->tenant_id }}</small>@endif</td>
            <td><span class="{{ ['ok' => 'badge-success-soft', 'failed' => 'badge-warning-soft', 'denied' => 'badge-danger-soft'][$r->outcome] ?? 'badge-theme' }}">{{ $r->outcome }}</span></td>
            <td>{{ $r->ip }}</td>
        </tr>
    @empty
        <tr><td colspan="6" class="text-muted">Nothing matches.</td></tr>
    @endforelse
    </tbody>
</table></div></div>
<div class="mt-3">{{ $rows->links() }}</div>
@endsection
