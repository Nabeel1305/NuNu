@extends('layouts.portal')
@section('title', 'Payment codes')
@use('App\Support\Money')
@section('content')
<div class="mb-4">
    <h4 class="fw-bold mb-1"><i class="ti ti-hash me-2" style="color:var(--brand-accent);"></i>Payment codes</h4>
    <p class="text-muted mb-0">One-time codes your app has issued. For security the code itself is never stored, so it is not shown here.</p>
</div>
<div class="card mb-3"><div class="card-body">
    <form method="get" class="filter-bar">
        <div style="flex:1 1 220px"><label class="form-label" for="q">Search</label><input id="q" name="q" class="form-control" placeholder="Code id, subscriber or merchant ref" value="{{ $filters['q'] }}"></div>
        <div><label class="form-label" for="state">State</label>
            <select id="state" name="state" class="form-select"><option value="">All</option>@foreach($states as $s)<option value="{{ $s->value }}" @selected($filters['state'] === $s->value)>{{ ucfirst($s->value) }}</option>@endforeach</select></div>
        <div><label class="form-label" for="from">From</label><input id="from" name="from" type="date" class="form-control" value="{{ $filters['from'] }}"></div>
        <div><label class="form-label" for="to">To</label><input id="to" name="to" type="date" class="form-control" value="{{ $filters['to'] }}"></div>
        <div class="d-flex gap-2"><button class="btn btn-brand">Filter</button><a href="{{ route('portal.codes.index') }}" class="btn btn-brand-outline">Reset</a></div>
    </form>
</div></div>
<div class="card">
    @if($codes->isEmpty())
        <div class="empty-state"><i class="ti ti-hash"></i><h5>No codes found</h5></div>
    @else
    <div class="table-responsive"><table class="table">
        <thead><tr><th>Code id</th><th>State</th><th class="text-end">Amount</th><th>Merchant</th><th>Subscriber</th><th>Expires</th></tr></thead>
        <tbody>
        @foreach($codes as $c)
            <tr>
                <td><a href="{{ route('portal.codes.show', $c->uuid) }}"><code>{{ \Illuminate\Support\Str::limit($c->uuid, 13, '…') }}</code></a></td>
                <td>@include('portal._badge', ['value' => $c->state->value])</td>
                <td class="text-end tabular">{{ Money::format($c->amount_minor, $c->currency) }}</td>
                <td>{{ $c->merchant?->reference }}</td>
                <td>{{ $c->subscriber?->reference }}</td>
                <td class="text-muted" title="{{ $c->expires_at }}">{{ $c->expires_at->isPast() ? 'expired ' : 'in ' }}{{ $c->expires_at->diffForHumans(['syntax' => \Carbon\CarbonInterface::DIFF_ABSOLUTE]) }}</td>
            </tr>
        @endforeach
        </tbody>
    </table></div>
    <div class="card-body border-top">{{ $codes->links('pagination::bootstrap-5') }}</div>
    @endif
</div>
@endsection
