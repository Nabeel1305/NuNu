@extends('layouts.portal')
@section('title', 'Payment code')
@section('breadcrumb', \Illuminate\Support\Str::limit($code->uuid, 13, '…'))
@use('App\Support\Money')
@use('App\Support\Mask')
@section('content')
<div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
    <div>
        <h4 class="fw-bold mb-1">{{ Money::format($code->amount_minor, $code->currency) }} @include('portal._badge', ['value' => $code->state->value])</h4>
        <p class="text-muted mb-0"><code>{{ $code->uuid }}</code></p>
    </div>
    <div class="d-flex gap-2">
        @if($code->state === \App\Services\Codes\CodeState::Issued && auth('tenant')->user()->can('cancel_code'))
            <form method="post" action="{{ route('portal.codes.cancel', $code->uuid) }}" data-confirm="The payer will no longer be able to use this code, and the hold on their funds is released." data-confirm-label="Cancel code">@csrf
                <button class="btn btn-brand-outline btn-sm" style="color:var(--danger)"><i class="ti ti-ban me-1"></i>Cancel code</button></form>
        @endif
        <a href="{{ route('portal.codes.index') }}" class="btn btn-brand-outline btn-sm"><i class="ti ti-arrow-left me-1"></i>All codes</a>
    </div>
</div>
<div class="card" style="max-width:780px">
    <div class="card-body">
        <dl class="dl">
            <dt>State</dt><dd>@include('portal._badge', ['value' => $code->state->value])</dd>
            <dt>Amount</dt><dd class="tabular">{{ Money::format($code->amount_minor, $code->currency) }}</dd>
            <dt>Merchant</dt><dd>{{ $code->merchant?->name }} <span class="text-muted">({{ $code->merchant?->reference }})</span></dd>
            <dt>Subscriber</dt><dd>{{ $code->subscriber?->reference }}</dd>
            <dt>Paying account</dt><dd>{{ $code->source_account_reference }}</dd>
            <dt>Hold reference</dt><dd>{{ $code->hold_reference ?? '—' }}</dd>
            <dt>Issued</dt><dd>{{ $code->created_at }}</dd>
            <dt>Expires</dt><dd>{{ $code->expires_at }}</dd>
            <dt>Redeemed</dt><dd>{{ $code->redeemed_at ?? '—' }}</dd>
            <dt>Caller</dt><dd>{{ Mask::phone($code->caller_number) }}</dd>
            @if($code->transaction)<dt>Transaction</dt><dd><a href="{{ route('portal.transactions.show', $code->transaction->uuid) }}">{{ $code->transaction->reference }}</a> @include('portal._badge', ['value' => $code->transaction->status])</dd>@endif
        </dl>
    </div>
</div>
@endsection
