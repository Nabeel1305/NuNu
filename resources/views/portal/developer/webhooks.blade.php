@extends('layouts.portal')
@section('title', 'Webhooks')
@section('content')
@php $canManage = auth('tenant')->user()->can('manage_webhooks'); @endphp
<div class="mb-4">
    <h4 class="fw-bold mb-1"><i class="ti ti-webhook me-2" style="color:var(--brand-accent);"></i>Webhooks</h4>
    <p class="text-muted mb-0">We call these HTTPS addresses when a payment event happens. Each request is signed in the <code>Offline-Signature</code> header with the endpoint's secret.</p>
</div>

@forelse($endpoints as $e)
<div class="card mb-3">
    <div class="card-body">
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
            <div>
                <div class="fw-semibold" style="word-break:break-all">{{ $e->url }}</div>
                <div class="mt-1">
                    @if($e->active)<span class="badge-success-soft">active</span>@else<span class="badge-warning-soft">paused</span>@endif
                    @if(($stats[$e->id]->failed ?? 0) > 0)<a href="{{ route('portal.deliveries', ['endpoint' => $e->id, 'status' => 'failed']) }}" class="badge-danger-soft text-decoration-none">{{ $stats[$e->id]->failed }} failed</a>@endif
                </div>
                <div class="kv mt-2">Events: {{ $e->events ? implode(', ', $e->events) : 'all' }}</div>
            </div>
            @if($canManage)
            <div class="d-flex gap-2 flex-wrap">
                <form method="post" action="{{ route('portal.webhooks.test', $e->id) }}">@csrf<button class="btn btn-sm btn-brand-outline"><i class="ti ti-send me-1"></i>Send test</button></form>
                <form method="post" action="{{ route('portal.webhooks.toggle', $e->id) }}">@csrf @method('PATCH')<button class="btn btn-sm btn-brand-outline">{{ $e->active ? 'Pause' : 'Enable' }}</button></form>
                <form method="post" action="{{ route('portal.webhooks.secret', $e->id) }}" data-confirm="A new signing secret replaces the old one immediately. Update your server before the next event, or signatures will fail." data-confirm-label="Rotate secret" data-confirm-tone="primary">@csrf<button class="btn btn-sm btn-brand-outline">Rotate secret</button></form>
                <form method="post" action="{{ route('portal.webhooks.destroy', $e->id) }}" data-confirm="This endpoint stops receiving events. Past deliveries stay in the log." data-confirm-label="Delete endpoint">@csrf @method('DELETE')<button class="btn btn-sm btn-brand-outline" style="color:var(--danger)">Delete</button></form>
            </div>
            @endif
        </div>
        @if($canManage)
        <details class="mt-3">
            <summary class="text-muted" style="cursor:pointer;font-size:13px">Edit address or events</summary>
            <form method="post" action="{{ route('portal.webhooks.update', $e->id) }}" class="mt-3">@csrf @method('PUT')
                <label class="form-label" for="url{{ $e->id }}">URL (https)</label>
                <input id="url{{ $e->id }}" name="url" type="url" class="form-control mb-2" value="{{ $e->url }}" required>
                <div class="d-flex flex-wrap gap-3 mb-3">
                    @foreach($events as $ev)<div class="form-check"><input class="form-check-input" type="checkbox" name="events[]" value="{{ $ev }}" id="e{{ $e->id }}{{ $loop->index }}" @checked($e->events === null || in_array($ev, $e->events))><label class="form-check-label" for="e{{ $e->id }}{{ $loop->index }}">{{ $ev }}</label></div>@endforeach
                </div>
                <button class="btn btn-brand btn-sm">Save</button>
            </form>
        </details>
        @endif
    </div>
</div>
@empty
<div class="card mb-3"><div class="empty-state"><i class="ti ti-webhook"></i><h5>No webhook endpoints</h5><p class="mb-0">Add one so your system learns about payments without polling.</p></div></div>
@endforelse

@if($canManage)
<div class="card" style="max-width:760px">
    <div class="card-header"><span class="section-title mb-0 mt-0">Add an endpoint</span></div>
    <div class="card-body">
        <form method="post" action="{{ route('portal.webhooks.store') }}">@csrf
            <label class="form-label" for="url">URL (must be public https)</label>
            <input id="url" name="url" type="url" class="form-control mb-3" required placeholder="https://api.example.com/pakapay/events" value="{{ old('url') }}">
            <div class="form-label">Events</div>
            <div class="d-flex flex-wrap gap-3 mb-1">
                @foreach($events as $ev)<div class="form-check"><input class="form-check-input" type="checkbox" name="events[]" value="{{ $ev }}" id="new{{ $loop->index }}" checked><label class="form-check-label" for="new{{ $loop->index }}">{{ $ev }}</label></div>@endforeach
            </div>
            <p class="text-muted" style="font-size:12.5px">Leave all ticked to receive every event, including ones added later.</p>
            <button class="btn btn-brand">Add endpoint</button>
        </form>
    </div>
</div>
@endif
@endsection
