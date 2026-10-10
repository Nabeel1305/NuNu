@extends('layouts.portal')
@section('title', $transaction->reference)
@section('breadcrumb', $transaction->reference)
@use('App\Support\Money')
@use('App\Support\Mask')
@section('content')
<div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
    <div>
        <h4 class="fw-bold mb-1">{{ Money::format($transaction->amount_minor, $transaction->currency) }} @include('portal._badge', ['value' => $transaction->status])</h4>
        <p class="text-muted mb-0"><code>{{ $transaction->reference }}</code></p>
    </div>
    <a href="{{ route('portal.transactions.index') }}" class="btn btn-brand-outline btn-sm"><i class="ti ti-arrow-left me-1"></i>All transactions</a>
</div>

<div class="row g-4">
    <div class="col-lg-7">
        <div class="card mb-4">
            <div class="card-header"><span class="section-title mb-0 mt-0">Details</span></div>
            <div class="card-body">
                <dl class="dl">
                    <dt>Transaction id</dt><dd><code>{{ $transaction->uuid }}</code></dd>
                    <dt>Status</dt><dd>@include('portal._badge', ['value' => $transaction->status])</dd>
                    <dt>Amount</dt><dd class="tabular">{{ Money::format($transaction->amount_minor, $transaction->currency) }}</dd>
                    <dt>Merchant</dt><dd>{{ $code->merchant?->name }} <span class="text-muted">({{ $code->merchant?->reference }})</span><div class="kv">Credits account {{ Mask::account($code->destination_account_number ?? $code->merchant?->account_number) }} · bank {{ $code->destination_bank_code ?? $code->merchant?->bank_code }}</div></dd>
                    <dt>Subscriber</dt><dd>{{ $code->subscriber?->reference }}</dd>
                    <dt>Paying account</dt><dd>{{ Mask::account($code->source_account_number ?? $code->source_account_reference) }} · bank {{ $code->source_bank_code ?? '—' }}</dd>
                    <dt>Caller</dt><dd>{{ Mask::phone($code->caller_number) }}</dd>
                    <dt>Settlement reference</dt><dd>{{ $transaction->settlement_reference ?? '—' }}</dd>
                    @if($transaction->failure_reason)<dt>Failure reason</dt><dd class="text-danger">{{ $transaction->failure_reason }}</dd>@endif
                    <dt>Created</dt><dd>{{ $transaction->created_at }}</dd>
                    <dt>Settled</dt><dd>{{ $transaction->settled_at ?? '—' }}</dd>
                    <dt>Payment code</dt><dd><a href="{{ route('portal.codes.show', $code->uuid) }}">{{ $code->uuid }}</a></dd>
                </dl>
            </div>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card mb-4">
            <div class="card-header"><span class="section-title mb-0 mt-0">Lifecycle</span></div>
            <div class="card-body">
                <ul class="timeline">
                    <li><strong>Code issued</strong><time>{{ $code->created_at }}</time></li>
                    @if($code->redeemed_at)<li><strong>Dialled and redeemed</strong><time>{{ $code->redeemed_at }}</time></li>@endif
                    @if($transaction->status === 'settled')<li class="is-good"><strong>Settled</strong><time>{{ $transaction->settled_at }}</time></li>
                    @elseif($transaction->status === 'failed')<li class="is-bad"><strong>Failed</strong><time>{{ $transaction->updated_at }}</time><span class="text-muted">{{ $transaction->failure_reason }}</span></li>
                    @else<li><strong>Waiting for your settlement system</strong><time>since {{ $transaction->created_at->diffForHumans() }}</time></li>@endif
                </ul>
            </div>
        </div>
        @if(auth('tenant')->user()->can('developer_view'))
        <div class="card">
            <div class="card-header"><span class="section-title mb-0 mt-0"><i class="ti ti-webhook"></i>Webhooks sent</span></div>
            <div class="table-responsive"><table class="table"><tbody>
            @forelse($deliveries as $d)
                <tr>
                    <td><a href="{{ route('portal.deliveries.show', $d->id) }}">{{ $d->event_type }}</a><div class="kv">{{ $d->created_at }}</div></td>
                    <td class="text-end">@include('portal._badge', ['value' => $d->status])</td>
                </tr>
            @empty
                <tr><td class="text-muted">No webhook has been sent for this payment (or the log has been pruned).</td></tr>
            @endforelse
            </tbody></table></div>
        </div>
        @endif
    </div>
</div>
@endsection
