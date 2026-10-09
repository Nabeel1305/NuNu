@extends('layouts.portal')
@section('title', 'Transactions')
@use('App\Support\Money')
@section('content')
<div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
    <div>
        <h4 class="fw-bold mb-1"><i class="ti ti-arrows-exchange me-2" style="color:var(--brand-accent);"></i>Transactions</h4>
        <p class="text-muted mb-0">Every payment made with a code from your organisation.</p>
    </div>
    <a href="{{ route('portal.transactions.export', request()->query()) }}" class="btn btn-brand-outline btn-sm"><i class="ti ti-download me-1"></i>Export CSV</a>
</div>

<div class="card mb-3">
    <div class="card-body">
        <form method="get" class="filter-bar">
            <div style="flex:1 1 220px"><label class="form-label" for="q">Search</label><input id="q" name="q" class="form-control" placeholder="Reference, id or settlement ref" value="{{ $filters['q'] }}"></div>
            <div><label class="form-label" for="status">Status</label>
                <select id="status" name="status" class="form-select"><option value="">All</option>@foreach($statuses as $s)<option value="{{ $s }}" @selected($filters['status'] === $s)>{{ ucfirst($s) }}</option>@endforeach</select></div>
            <div><label class="form-label" for="from">From</label><input id="from" name="from" type="date" class="form-control" value="{{ $filters['from'] }}"></div>
            <div><label class="form-label" for="to">To</label><input id="to" name="to" type="date" class="form-control" value="{{ $filters['to'] }}"></div>
            <div style="min-width:100px"><label class="form-label" for="min">Min amount</label><input id="min" name="min" class="form-control" inputmode="decimal" placeholder="0.00" value="{{ $filters['min'] }}"></div>
            <div style="min-width:100px"><label class="form-label" for="max">Max amount</label><input id="max" name="max" class="form-control" inputmode="decimal" placeholder="0.00" value="{{ $filters['max'] }}"></div>
            <div><label class="form-label" for="merchant">Merchant ref</label><input id="merchant" name="merchant" class="form-control" value="{{ $filters['merchant'] }}"></div>
            <div><label class="form-label" for="subscriber">Subscriber ref</label><input id="subscriber" name="subscriber" class="form-control" value="{{ $filters['subscriber'] }}"></div>
            <div class="d-flex gap-2"><button class="btn btn-brand">Filter</button><a href="{{ route('portal.transactions.index') }}" class="btn btn-brand-outline">Reset</a></div>
        </form>
    </div>
</div>

@if($totals->isNotEmpty())
<p class="text-muted mb-3">{{ number_format($transactions->total()) }} match
    @foreach($totals as $t) · <strong>{{ Money::format($t->total, $t->currency) }}</strong> ({{ number_format($t->n) }} in {{ $t->currency }})@endforeach</p>
@endif

<div class="card">
    @if($transactions->isEmpty())
        <div class="empty-state"><i class="ti ti-receipt-off"></i><h5>No transactions found</h5><p class="mb-0">Try widening the filters.</p></div>
    @else
    <div class="table-responsive">
        <table class="table">
            <thead><tr><th>Reference</th><th>Status</th><th class="text-end">Amount</th><th>Merchant</th><th>Subscriber</th><th>Created</th></tr></thead>
            <tbody>
            @foreach($transactions as $t)
                <tr>
                    <td><a href="{{ route('portal.transactions.show', $t->uuid) }}" class="fw-semibold">{{ $t->reference }}</a>@if($t->failure_reason)<div class="kv">{{ $t->failure_reason }}</div>@endif</td>
                    <td>@include('portal._badge', ['value' => $t->status])</td>
                    <td class="text-end tabular">{{ Money::format($t->amount_minor, $t->currency) }}</td>
                    <td>{{ $t->paymentCode?->merchant?->name }}<div class="kv">{{ $t->paymentCode?->merchant?->reference }}</div></td>
                    <td>{{ $t->paymentCode?->subscriber?->reference }}</td>
                    <td class="text-muted" title="{{ $t->created_at }}">{{ $t->created_at->diffForHumans() }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
    <div class="card-body border-top">{{ $transactions->links('pagination::bootstrap-5') }}</div>
    @endif
</div>
@endsection
