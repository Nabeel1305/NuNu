@extends('layouts.portal')
@section('title', 'Merchants')
@section('content')
<div class="mb-4">
    <h4 class="fw-bold mb-1"><i class="ti ti-building-store me-2" style="color:var(--brand-accent);"></i>Merchants</h4>
    <p class="text-muted mb-0">Payees your system has registered, and the account each one credits.</p>
</div>
<div class="card mb-3"><div class="card-body"><form method="get" class="filter-bar"><div style="flex:1 1 260px"><label class="form-label" for="q">Search</label><input id="q" name="q" class="form-control" placeholder="Reference or name" value="{{ $q }}"></div><button class="btn btn-brand">Search</button></form></div></div>
<div class="card">
    @if($rows->isEmpty())<div class="empty-state"><i class="ti ti-building-store"></i><h5>No merchants</h5></div>
    @else
    <div class="table-responsive"><table class="table">
        <thead><tr><th>Name</th><th>Reference</th><th>Credited account</th><th class="text-end">Codes</th></tr></thead>
        <tbody>@foreach($rows as $m)<tr><td class="fw-semibold">{{ $m->name }}</td><td>{{ $m->reference }}</td><td>{{ $m->account_reference }}</td><td class="text-end tabular">{{ number_format($m->codes_count) }}</td></tr>@endforeach</tbody>
    </table></div>
    <div class="card-body border-top">{{ $rows->links('pagination::bootstrap-5') }}</div>
    @endif
</div>
@endsection
