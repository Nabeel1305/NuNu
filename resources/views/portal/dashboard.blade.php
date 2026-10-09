@extends('layouts.portal')
@section('title', 'Overview')
@use('App\Support\Money')
@section('content')
<div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
    <div>
        <h4 class="fw-bold mb-1"><i class="ti ti-layout-dashboard me-2" style="color:var(--brand-accent);"></i>Welcome back, {{ strtok(auth('tenant')->user()->name, ' ') }}</h4>
        <p class="text-muted mb-0">{{ $tenant->name }} · {{ $periods[$period] }}</p>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        @foreach($periods as $k => $label)
            <a href="{{ route('portal.dashboard', ['period' => $k]) }}" class="filter-chip {{ $period === $k ? 'active' : '' }}">{{ $label }}</a>
        @endforeach
    </div>
</div>

@if(auth('tenant')->user()->can('money'))
<div class="metric-grid">
    <div class="metric">
        <div class="label"><i class="ti ti-circle-check text-success"></i>Settled</div>
        @forelse($settled as $s)
            <div class="value">{{ Money::format($s->total, $s->currency) }}</div>
        @empty
            <div class="value">{{ Money::format(0, 'NGN') }}</div>
        @endforelse
        <div class="sub">{{ number_format($settledCount) }} payment{{ $settledCount === 1 ? '' : 's' }}</div>
    </div>
    <div class="metric">
        <div class="label"><i class="ti ti-percentage text-info"></i>Success rate</div>
        <div class="value">{{ $successRate === null ? '—' : $successRate . '%' }}</div>
        <div class="sub">{{ number_format($failedCount) }} failed</div>
    </div>
    <div class="metric">
        <div class="label"><i class="ti ti-hash" style="color:var(--brand-accent)"></i>Codes issued</div>
        <div class="value">{{ number_format($codesIssued) }}</div>
        <div class="sub">{{ number_format($openCodes) }} open right now</div>
    </div>
    <div class="metric">
        <div class="label"><i class="ti ti-clock-hour-4 text-warning"></i>Waiting on you</div>
        <div class="value">{{ number_format($pendingCount) }}</div>
        <div class="sub">@if($oldestPending)oldest {{ \Illuminate\Support\Carbon::parse($oldestPending)->diffForHumans() }}@else nothing pending @endif</div>
    </div>
</div>

<div class="row g-4 mb-4">
    <div class="col-lg-8">
        <div class="card h-100">
            <div class="card-header"><span class="section-title mb-0 mt-0"><i class="ti ti-chart-bar"></i>Settled per day @if($chart['currency'])<span class="text-muted fw-normal">({{ $chart['currency'] }})</span>@endif</span></div>
            <div class="card-body">
                @if($settledCount === 0)
                    <div class="empty-state"><i class="ti ti-chart-bar-off"></i><h5>No settled payments in this period</h5><p class="mb-0">Payments appear here as soon as your settlement system confirms them.</p></div>
                @else
                    <div class="chart-box"><canvas id="settledChart" data-chart="{{ json_encode($chart) }}"></canvas></div>
                @endif
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card h-100">
            <div class="card-header"><span class="section-title mb-0 mt-0"><i class="ti ti-building-store"></i>Top merchants</span></div>
            <div class="card-body p-0">
                @forelse($topMerchants as $m)
                    <div class="d-flex justify-content-between align-items-center px-3 py-3 border-bottom">
                        <div><div class="fw-semibold">{{ $m->name }}</div><div class="kv">{{ $m->reference }} · {{ $m->n }} payment{{ $m->n == 1 ? '' : 's' }}</div></div>
                        <div class="fw-semibold tabular">{{ Money::format($m->total, $m->currency) }}</div>
                    </div>
                @empty
                    <div class="empty-state py-4"><p class="mb-0">No merchant activity yet.</p></div>
                @endforelse
            </div>
        </div>
    </div>
</div>

<div class="row g-4 mb-4">
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header"><span class="section-title mb-0 mt-0">Code outcomes</span></div>
            <div class="card-body">
                @php $total = max(1, $codesIssued); @endphp
                @foreach(['settled' => 'success', 'redeemed' => 'info', 'issued' => 'info', 'failed' => 'danger', 'expired' => 'warning', 'cancelled' => 'secondary'] as $state => $tone)
                    @php $n = (int) ($codeCounts[$state] ?? 0); @endphp
                    <div class="mb-3">
                        <div class="d-flex justify-content-between"><span class="text-capitalize">{{ $state }}</span><span class="tabular text-muted">{{ number_format($n) }}</span></div>
                        <div class="progress" style="height:6px"><div class="progress-bar bg-{{ $tone }}" style="width: {{ round($n / $total * 100) }}%"></div></div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center"><span class="section-title mb-0 mt-0"><i class="ti ti-alert-triangle"></i>Recent failures</span><a href="{{ route('portal.transactions.index', ['status' => 'failed']) }}" class="btn btn-brand-outline btn-sm">View all</a></div>
            <div class="table-responsive">
                <table class="table">
                    <tbody>
                    @forelse($recentFailures as $t)
                        <tr>
                            <td><a href="{{ route('portal.transactions.show', $t->uuid) }}" class="fw-semibold">{{ $t->reference }}</a><div class="kv">{{ $t->failure_reason ?? 'No reason given' }}</div></td>
                            <td class="text-end tabular">{{ Money::format($t->amount_minor, $t->currency) }}<div class="kv">{{ $t->created_at->diffForHumans() }}</div></td>
                        </tr>
                    @empty
                        <tr><td class="text-muted">No failures. 🎉</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
@endif

@if(auth('tenant')->user()->can('developer_view'))
<h5 class="fw-bold mb-3">Integration health</h5>
<div class="metric-grid">
    <div class="metric">
        <div class="label"><i class="ti ti-send" style="color:var(--brand-accent)"></i>Webhook delivery</div>
        <div class="value">{{ $deliveries['rate'] === null ? '—' : $deliveries['rate'] . '%' }}</div>
        <div class="sub">{{ number_format($deliveries['delivered']) }} delivered · {{ number_format($deliveries['failed']) }} failed · {{ number_format($deliveries['pending']) }} pending</div>
    </div>
    <div class="metric">
        <div class="label"><i class="ti ti-webhook text-info"></i>Endpoints</div>
        <div class="value">{{ $activeEndpoints }}<span class="text-muted" style="font-size:16px"> / {{ $endpoints }}</span></div>
        <div class="sub">active of configured</div>
    </div>
    <div class="metric">
        <div class="label"><i class="ti ti-key text-warning"></i>Active API keys</div>
        <div class="value">{{ $activeKeys }}</div>
        <div class="sub"><a href="{{ route('portal.keys') }}">Manage keys</a></div>
    </div>
    <div class="metric">
        <div class="label"><i class="ti ti-phone-call text-success"></i>Voice numbers</div>
        <div class="value">{{ $numbers }}</div>
        <div class="sub">active</div>
    </div>
</div>
@if($failedDeliveries->isNotEmpty())
<div class="card mb-4">
    <div class="card-header"><span class="section-title mb-0 mt-0"><i class="ti ti-alert-circle"></i>Failed webhook deliveries</span></div>
    <div class="table-responsive"><table class="table"><tbody>
        @foreach($failedDeliveries as $d)
            <tr>
                <td><a href="{{ route('portal.deliveries.show', $d->id) }}">{{ $d->event_type }}</a><div class="kv">{{ $d->endpoint?->url }}</div></td>
                <td class="text-muted">{{ $d->last_error ?? 'Failed' }}</td>
                <td class="text-end text-muted">{{ $d->created_at->diffForHumans() }}</td>
            </tr>
        @endforeach
    </tbody></table></div>
</div>
@endif
@endif
@endsection

@if(auth('tenant')->user()->can('money'))
@push('scripts')
<script src="{{ asset('assets/js/chart.js') }}"></script>
<script src="{{ asset('assets/js/portal-dashboard.js') }}?v={{ filemtime(public_path('assets/js/portal-dashboard.js')) }}"></script>
@endpush
@endif
