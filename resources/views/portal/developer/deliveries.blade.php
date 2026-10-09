@extends('layouts.portal')
@section('title', 'Webhook deliveries')
@section('content')
<div class="mb-4">
    <h4 class="fw-bold mb-1"><i class="ti ti-send me-2" style="color:var(--brand-accent);"></i>Webhook deliveries</h4>
    <p class="text-muted mb-0">Every event we tried to send you. Finished deliveries are kept for 30 days.</p>
</div>
<div class="card mb-3"><div class="card-body">
    <form method="get" class="filter-bar">
        <div><label class="form-label" for="status">Status</label><select id="status" name="status" class="form-select"><option value="">All</option>@foreach(['pending','delivered','failed'] as $s)<option value="{{ $s }}" @selected($filters['status'] === $s)>{{ ucfirst($s) }}</option>@endforeach</select></div>
        <div><label class="form-label" for="event">Event</label><select id="event" name="event" class="form-select"><option value="">All</option>@foreach($events as $ev)<option value="{{ $ev }}" @selected($filters['event'] === $ev)>{{ $ev }}</option>@endforeach</select></div>
        <div style="flex:1 1 240px"><label class="form-label" for="endpoint">Endpoint</label><select id="endpoint" name="endpoint" class="form-select"><option value="">All</option>@foreach($endpoints as $e)<option value="{{ $e->id }}" @selected((string) $filters['endpoint'] === (string) $e->id)>{{ $e->url }}</option>@endforeach</select></div>
        <div class="d-flex gap-2"><button class="btn btn-brand">Filter</button><a href="{{ route('portal.deliveries') }}" class="btn btn-brand-outline">Reset</a></div>
    </form>
</div></div>
<div class="card">
    @if($rows->isEmpty())<div class="empty-state"><i class="ti ti-send-off"></i><h5>No deliveries</h5></div>
    @else
    <div class="table-responsive"><table class="table">
        <thead><tr><th>Event</th><th>Endpoint</th><th>Status</th><th>Attempts</th><th>Last result</th><th>When</th></tr></thead>
        <tbody>
        @foreach($rows as $d)
            <tr>
                <td><a href="{{ route('portal.deliveries.show', $d->id) }}" class="fw-semibold">{{ $d->event_type }}</a></td>
                <td class="text-muted" style="max-width:260px;word-break:break-all">{{ $d->endpoint?->url ?? 'deleted endpoint' }}</td>
                <td>@include('portal._badge', ['value' => $d->status])</td>
                <td>{{ $d->attempts }}</td>
                <td class="text-muted">{{ $d->last_status_code }} {{ $d->last_error }}</td>
                <td class="text-muted" title="{{ $d->created_at }}">{{ $d->created_at->diffForHumans() }}</td>
            </tr>
        @endforeach
        </tbody>
    </table></div>
    <div class="card-body border-top">{{ $rows->links('pagination::bootstrap-5') }}</div>
    @endif
</div>
@endsection
