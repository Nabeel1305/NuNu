@extends('layouts.admin')
@section('title', 'Recipients')
@section('content')
<div class="mb-4"><h4 class="fw-bold mb-1"><i class="ti ti-building-store me-2" style="color:var(--brand-accent);"></i>Recipients</h4><p class="text-muted mb-0">Merchants registered by tenants, and accounts created automatically from offline “pay any account” codes (reference <code>direct-…</code>).</p></div>
<form class="row g-2 mb-3" method="get"><div class="col-md-4"><input name="q" class="form-control form-control-sm" placeholder="Reference, name, account" value="{{ request('q') }}"></div>
<div class="col-md-3"><select name="tenant" class="form-select form-select-sm"><option value="">Any tenant</option>@foreach($tenants as $id => $n)<option value="{{ $id }}" @selected(request('tenant') == $id)>{{ $n }}</option>@endforeach</select></div>
<div class="col-auto form-check align-self-center"><input class="form-check-input" type="checkbox" name="direct" value="1" id="direct" @checked(request()->boolean('direct'))><label class="form-check-label" for="direct">Auto-created only</label></div><div class="col-auto"><button class="btn btn-sm btn-brand">Filter</button></div></form>
<div class="card"><div class="table-responsive"><table class="table mb-0"><thead><tr><th>Reference</th><th>Name</th><th>Account</th><th>Short id</th><th>Tenant</th><th>Payments</th></tr></thead><tbody>
@forelse($rows as $r)<tr><td>{{ $r->reference }}@if(str_starts_with($r->reference, 'direct-')) <span class="badge-theme">auto</span>@endif</td><td>{{ $r->name }}</td><td>{{ $r->bank_code }} {{ $r->account_number }}</td><td>{{ $r->short_id }}</td><td><a href="{{ route('admin.tenants.show', $r->tenant_id) }}">{{ $r->tenant }}</a></td><td>{{ $r->codes }}</td></tr>
@empty<tr><td colspan="6" class="text-muted">None.</td></tr>@endforelse</tbody></table></div></div>
<div class="mt-3">{{ $rows->links() }}</div>
@endsection
