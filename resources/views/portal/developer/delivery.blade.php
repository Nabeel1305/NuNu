@extends('layouts.portal')
@section('title', 'Delivery')
@section('breadcrumb', $delivery->event_type)
@section('content')
<div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
    <div>
        <h4 class="fw-bold mb-1">{{ $delivery->event_type }} @include('portal._badge', ['value' => $delivery->status])</h4>
        <p class="text-muted mb-0"><code>{{ $delivery->event_id }}</code></p>
    </div>
    <div class="d-flex gap-2">
        @if($delivery->status !== 'delivered' && auth('tenant')->user()->can('manage_webhooks'))
            <form method="post" action="{{ route('portal.deliveries.retry', $delivery->id) }}">@csrf<button class="btn btn-brand btn-sm"><i class="ti ti-refresh me-1"></i>Retry now</button></form>
        @endif
        <a href="{{ route('portal.deliveries') }}" class="btn btn-brand-outline btn-sm"><i class="ti ti-arrow-left me-1"></i>All deliveries</a>
    </div>
</div>
<div class="row g-4">
    <div class="col-lg-5"><div class="card"><div class="card-body"><dl class="dl">
        <dt>Endpoint</dt><dd style="word-break:break-all">{{ $delivery->endpoint?->url ?? 'deleted endpoint' }}</dd>
        <dt>Attempts</dt><dd>{{ $delivery->attempts }}</dd>
        <dt>Response</dt><dd>{{ $delivery->last_status_code ?? '—' }}</dd>
        <dt>Last error</dt><dd class="{{ $delivery->last_error ? 'text-danger' : '' }}">{{ $delivery->last_error ?? '—' }}</dd>
        <dt>Next attempt</dt><dd>{{ $delivery->next_attempt_at ?? '—' }}</dd>
        <dt>Delivered</dt><dd>{{ $delivery->delivered_at ?? '—' }}</dd>
        <dt>Created</dt><dd>{{ $delivery->created_at }}</dd>
    </dl></div></div></div>
    <div class="col-lg-7"><div class="card"><div class="card-header"><span class="section-title mb-0 mt-0">Payload sent</span></div><div class="card-body"><pre class="payload">{{ json_encode($delivery->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre></div></div></div>
</div>
@endsection
