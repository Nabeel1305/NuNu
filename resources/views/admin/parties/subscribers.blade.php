@extends('layouts.admin')
@section('title', 'Payers')
@section('content')
<div class="mb-4"><h4 class="fw-bold mb-1"><i class="ti ti-users me-2" style="color:var(--brand-accent);"></i>Payers</h4><p class="text-muted mb-0">Subscribers registered by every tenant. Phone numbers are shown in full to operators.</p></div>
<form class="row g-2 mb-3" method="get"><div class="col-md-4"><input name="q" class="form-control form-control-sm" placeholder="Reference, phone, account" value="{{ request('q') }}"></div>
<div class="col-md-3"><select name="tenant" class="form-select form-select-sm"><option value="">Any tenant</option>@foreach($tenants as $id => $n)<option value="{{ $id }}" @selected(request('tenant') == $id)>{{ $n }}</option>@endforeach</select></div><div class="col-auto"><button class="btn btn-sm btn-brand">Filter</button></div></form>
<div class="card"><div class="table-responsive"><table class="table mb-0"><thead><tr><th>Reference</th><th>Phone</th><th>Account</th><th>Tenant</th><th>Codes</th><th>Phones</th></tr></thead><tbody>
@forelse($rows as $r)<tr><td>{{ $r->reference }}</td><td>{{ $r->phone }}</td><td>{{ $r->bank_code }} ····{{ substr((string) $r->account_number, -4) }}</td><td><a href="{{ route('admin.tenants.show', $r->tenant_id) }}">{{ $r->tenant }}</a></td><td>{{ $r->codes }}</td><td>@if($r->phones)<a href="{{ route('admin.devices.index', ['tenant' => $r->tenant_id, 'q' => $r->reference]) }}">{{ $r->phones }}</a>@else 0 @endif</td></tr>
@empty<tr><td colspan="6" class="text-muted">Nobody yet.</td></tr>@endforelse</tbody></table></div></div>
<div class="mt-3">{{ $rows->links() }}</div>
@endsection
