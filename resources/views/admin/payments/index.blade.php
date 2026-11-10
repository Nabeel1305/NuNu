@extends('layouts.admin')
@section('title', 'Payments')
@section('content')
<div class="mb-4 d-flex justify-content-between align-items-start flex-wrap gap-2">
    <div>
        <h4 class="fw-bold mb-1"><i class="ti ti-arrows-exchange me-2" style="color:var(--brand-accent);"></i>Payments</h4>
        <p class="text-muted mb-0">Every tenant's payments. @if($stuckCount)<a href="{{ route('admin.payments.index', ['stuck' => 1]) }}" class="badge-warning-soft text-decoration-none">{{ $stuckCount }} stuck pending</a>@endif</p>
    </div>
    @if(auth('admin')->user()->can('reports'))<a class="btn btn-brand-outline btn-sm" href="{{ route('admin.payments.export', request()->query()) }}"><i class="ti ti-download me-1"></i>Export CSV</a>@endif
</div>
<form class="row g-2 mb-3" method="get">
    <div class="col-md-3"><input name="q" class="form-control form-control-sm" placeholder="Reference, phone, account, payer" value="{{ request('q') }}"></div>
    <div class="col-md-2"><select name="tenant" class="form-select form-select-sm"><option value="">Any tenant</option>@foreach($tenants as $id => $n)<option value="{{ $id }}" @selected(request('tenant') == $id)>{{ $n }}</option>@endforeach</select></div>
    <div class="col-md-1"><select name="status" class="form-select form-select-sm"><option value="">Any</option>@foreach(['pending','settled','failed'] as $s)<option @selected(request('status') === $s)>{{ $s }}</option>@endforeach</select></div>
    <div class="col-md-1"><select name="origin" class="form-select form-select-sm"><option value="">Online+offline</option><option value="device" @selected(request('origin') === 'device')>Offline</option><option value="issued" @selected(request('origin') === 'issued')>Issued</option></select></div>
    <div class="col-md-2 d-flex gap-1"><input name="min" type="number" step="0.01" class="form-control form-control-sm" placeholder="Min ₦" value="{{ request('min') }}"><input name="max" type="number" step="0.01" class="form-control form-control-sm" placeholder="Max ₦" value="{{ request('max') }}"></div>
    <div class="col-md-2 d-flex gap-1"><input name="from" type="date" class="form-control form-control-sm" value="{{ request('from') }}" aria-label="From"><input name="to" type="date" class="form-control form-control-sm" value="{{ request('to') }}" aria-label="To"></div>
    <div class="col-auto form-check align-self-center"><input class="form-check-input" type="checkbox" name="stuck" value="1" id="stuck" @checked(request()->boolean('stuck'))><label class="form-check-label" for="stuck">Stuck only</label></div>
    <div class="col-auto"><button class="btn btn-sm btn-brand">Filter</button></div>
</form>
<div class="card"><div class="table-responsive"><table class="table mb-0">
    <thead><tr><th>When</th><th>Tenant</th><th>Reference</th><th>Amount</th><th>Status</th><th>Via</th><th>To</th></tr></thead>
    <tbody>
    @forelse($rows as $r)
        <tr>
            <td>{{ \Illuminate\Support\Carbon::parse($r->created_at)->format('d M H:i') }}</td>
            <td>{{ $r->tenant }}</td>
            <td><a href="{{ route('admin.payments.show', $r->uuid) }}"><code>{{ $r->reference }}</code></a></td>
            <td>{{ \App\Support\Money::format($r->amount_minor, $r->currency) }}</td>
            <td><span class="{{ ['settled' => 'badge-success-soft', 'failed' => 'badge-danger-soft'][$r->status] ?? 'badge-warning-soft' }}">{{ $r->status }}</span></td>
            <td>{{ $r->origin === 'device' ? 'phone (offline)' : 'issued code' }}</td>
            <td>{{ $r->to_bank }} ····{{ substr((string) $r->to_account, -4) }}</td>
        </tr>
    @empty
        <tr><td colspan="7" class="text-muted">Nothing matches.</td></tr>
    @endforelse
    </tbody>
</table></div></div>
<div class="mt-3">{{ $rows->links() }}</div>
@endsection
