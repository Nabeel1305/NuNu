@extends('layouts.admin')
@section('title', $tx->reference)
@section('content')
@php $can = auth('admin')->user(); $stuck = $tx->status === 'pending' && $tx->created_at->lte(now()->subMinutes($stuckAfter)); @endphp
<div class="mb-4">
    <a href="{{ route('admin.payments.index') }}" class="text-muted small">← Payments</a>
    <h4 class="fw-bold mb-1 mt-1"><code>{{ $tx->reference }}</code>
        <span class="{{ ['settled' => 'badge-success-soft', 'failed' => 'badge-danger-soft'][$tx->status] ?? 'badge-warning-soft' }}">{{ $tx->status }}</span>
        @if($stuck)<span class="badge-warning-soft">stuck</span>@endif</h4>
    <p class="text-muted mb-0">{{ $tenant->name }} · {{ \App\Support\Money::format($tx->amount_minor, $tx->currency) }} · {{ $tx->created_at->format('d M Y H:i') }}</p>
</div>
@if($errors->any())<div class="alert alert-danger">{{ $errors->first() }}</div>@endif
<div class="row g-4 mb-4">
    <div class="col-lg-6"><div class="card h-100"><div class="card-header"><span class="section-title mb-0 mt-0">Payment</span></div><div class="card-body"><dl class="dl">
        <dt>Payer</dt><dd>{{ $code->subscriber->reference }} <small class="text-muted">{{ $code->subscriber->phone }}</small></dd>
        <dt>From</dt><dd>{{ $code->source_bank_code }} ····{{ substr((string) $code->source_account_number, -4) }}</dd>
        <dt>To</dt><dd>{{ $code->merchant->reference }} <small class="text-muted">{{ $code->destination_bank_code }} ····{{ substr((string) $code->destination_account_number, -4) }}</small></dd>
        <dt>Via</dt><dd>{{ $code->origin === 'device' ? 'Phone, offline (signed code)' : 'Issued code' }}</dd>
        <dt>Code state</dt><dd>{{ $code->state->value ?? $code->state }}</dd>
        <dt>Hold</dt><dd>{{ $code->hold_reference ?? 'none' }}</dd>
        <dt>Settlement ref</dt><dd>{{ $tx->settlement_reference ?? '·' }}</dd>
        @if($tx->failure_reason)<dt>Why it failed</dt><dd>{{ $tx->failure_reason }}</dd>@endif
    </dl></div></div></div>
    <div class="col-lg-6"><div class="card h-100"><div class="card-header"><span class="section-title mb-0 mt-0">Actions</span></div><div class="card-body">
        @if($tx->status === 'pending' && $can->can('payments'))
            <form method="post" action="{{ route('admin.payments.recheck', $tx->uuid) }}" class="mb-3">@csrf<button class="btn btn-brand">Re-check with the tenant</button><div class="form-text">Asks the tenant what became of it and acts on a clear answer, the same as the scheduler.</div></form>
            <form method="post" action="{{ route('admin.payments.mark-failed', $tx->uuid) }}" data-confirm="The tenant is asked first: money already captured is recorded as settled, never failed." data-confirm-label="Close it">@csrf
                <label class="form-label" for="reason">Close as failed (reason)</label>
                <div class="d-flex gap-2"><input id="reason" name="reason" class="form-control" required minlength="5" maxlength="200" placeholder="Why it will not resolve"><button class="btn btn-danger">Mark failed</button></div>
            </form>
        @elseif((string) ($code->state->value ?? $code->state) === 'issued' && $can->can('payments'))
            <form method="post" action="{{ route('admin.codes.cancel', $code->uuid) }}" data-confirm="The code stops working and its hold is released." data-confirm-label="Cancel code">@csrf<button class="btn btn-danger">Cancel this code</button></form>
        @else
            <p class="text-muted mb-0">@if($tx->status === 'pending')Your role cannot change payments.@else Nothing to do: this payment is {{ $tx->status }}.@endif</p>
        @endif
        <hr><a href="{{ route('admin.tenants.show', $tenant) }}">Open {{ $tenant->name }}</a>
    </div></div></div>
</div>
<div class="card mb-4"><div class="card-header"><span class="section-title mb-0 mt-0">Timeline</span></div>
<div class="table-responsive"><table class="table mb-0"><thead><tr><th>When</th><th>Event</th><th>What</th></tr></thead><tbody>
@forelse($trail as $a)<tr><td>{{ $a->created_at->format('d M H:i:s') }}</td><td><code>{{ $a->event_type }}</code></td><td>{{ $a->description }}</td></tr>
@empty<tr><td colspan="3" class="text-muted">No events recorded.</td></tr>@endforelse</tbody></table></div></div>
<div class="card"><div class="card-header"><span class="section-title mb-0 mt-0">Webhook deliveries about it</span></div>
<div class="table-responsive"><table class="table mb-0"><thead><tr><th>Event</th><th>Status</th><th>Attempts</th><th>Last result</th><th></th></tr></thead><tbody>
@forelse($deliveries as $d)<tr><td>{{ $d->event_type }}</td><td><span class="{{ ['delivered' => 'badge-success-soft', 'failed' => 'badge-danger-soft'][$d->status] ?? 'badge-warning-soft' }}">{{ $d->status }}</span></td><td>{{ $d->attempts }}</td><td>{{ $d->last_status_code }} {{ $d->last_error }}</td>
<td class="text-end">@if($d->status !== 'delivered' && $can->can('webhooks'))<form method="post" action="{{ route('admin.deliveries.retry', $d->id) }}">@csrf<button class="btn btn-sm btn-brand-outline">Retry</button></form>@endif</td></tr>
@empty<tr><td colspan="5" class="text-muted">None.</td></tr>@endforelse</tbody></table></div></div>
@endsection
