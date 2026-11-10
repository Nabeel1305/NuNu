@extends('layouts.admin')
@section('title', 'Delivery')
@section('content')
<div class="mb-4"><a href="{{ route('admin.deliveries.index') }}" class="text-muted small">← Deliveries</a><h4 class="fw-bold mb-1 mt-1">{{ $d->event_type }} <span class="{{ ['delivered' => 'badge-success-soft', 'failed' => 'badge-danger-soft'][$d->status] ?? 'badge-warning-soft' }}">{{ $d->status }}</span></h4>
<p class="text-muted mb-0">{{ $tenant?->name }} · {{ $endpoint?->url }} · {{ $d->attempts }} attempt(s) · last: {{ $d->last_status_code }} {{ $d->last_error }}</p></div>
<div class="card"><div class="card-header"><span class="section-title mb-0 mt-0">Payload sent</span></div><div class="card-body"><pre class="payload mb-0">{{ json_encode($d->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre></div></div>
@endsection
