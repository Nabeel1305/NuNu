@extends('layouts.portal')
@section('title', 'Audit log')
@section('content')
<div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
    <div>
        <h4 class="fw-bold mb-1"><i class="ti ti-history me-2" style="color:var(--brand-accent);"></i>Audit log</h4>
        <p class="text-muted mb-0">A permanent, tamper-evident record of what happened in your organisation — {{ number_format($total) }} entries. Entries can only be added, never changed.</p>
    </div>
    <form method="post" action="{{ route('portal.audit.verify') }}">@csrf<button class="btn btn-brand-outline btn-sm"><i class="ti ti-shield-check me-1"></i>Verify chain integrity</button></form>
</div>
<div class="card mb-3"><div class="card-body"><form method="get" class="filter-bar">
    <div style="flex:1 1 260px"><label class="form-label" for="event">Event</label><select id="event" name="event" class="form-select"><option value="">All events</option>@foreach($events as $e)<option value="{{ $e }}" @selected($event === $e)>{{ $e }}</option>@endforeach</select></div>
    <div class="d-flex gap-2"><button class="btn btn-brand">Filter</button><a href="{{ route('portal.audit') }}" class="btn btn-brand-outline">Reset</a></div>
</form></div></div>
<div class="card">
    @if($rows->isEmpty())<div class="empty-state"><i class="ti ti-history"></i><h5>Nothing recorded yet</h5></div>
    @else
    <div class="table-responsive"><table class="table">
        <thead><tr><th>#</th><th>Event</th><th>Description</th><th>Who</th><th>When</th></tr></thead>
        <tbody>
        @foreach($rows as $r)
            <tr>
                <td class="text-muted">{{ $r->id }}</td>
                <td><code>{{ $r->event_type }}</code></td>
                <td>{{ $r->description }}@if($r->reference)<div class="kv">{{ $r->reference }}</div>@endif</td>
                <td class="text-muted">{{ str_replace('_', ' ', $r->actor_type) }}</td>
                <td class="text-muted" title="{{ $r->created_at }}">{{ $r->created_at->diffForHumans() }}</td>
            </tr>
        @endforeach
        </tbody>
    </table></div>
    <div class="card-body border-top">{{ $rows->links('pagination::bootstrap-5') }}</div>
    @endif
</div>
@endsection
