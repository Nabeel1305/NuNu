@extends('layouts.portal')
@section('title', 'Subscribers')
@use('App\Support\Mask')
@section('content')
<div class="mb-4">
    <h4 class="fw-bold mb-1"><i class="ti ti-users me-2" style="color:var(--brand-accent);"></i>Subscribers</h4>
    <p class="text-muted mb-0">Payers your system has registered. Phone numbers are partly hidden.</p>
</div>
<div class="card mb-3"><div class="card-body"><form method="get" class="filter-bar"><div style="flex:1 1 260px"><label class="form-label" for="q">Search</label><input id="q" name="q" class="form-control" placeholder="Reference or phone prefix" value="{{ $q }}"></div><button class="btn btn-brand">Search</button></form></div></div>
<div class="card">
    @if($rows->isEmpty())<div class="empty-state"><i class="ti ti-users"></i><h5>No subscribers</h5></div>
    @else
    <div class="table-responsive"><table class="table">
        <thead><tr><th>Reference</th><th>Phone</th><th class="text-end">Codes</th><th>Registered</th></tr></thead>
        <tbody>@foreach($rows as $s)<tr><td class="fw-semibold">{{ $s->reference }}</td><td>{{ Mask::phone($s->phone) }}</td><td class="text-end tabular">{{ number_format($s->codes_count) }}</td><td class="text-muted">{{ $s->created_at->diffForHumans() }}</td></tr>@endforeach</tbody>
    </table></div>
    <div class="card-body border-top">{{ $rows->links('pagination::bootstrap-5') }}</div>
    @endif
</div>
@endsection
