@extends('layouts.admin')
@section('title', 'Calls')
@section('content')
<div class="mb-4">
    <h4 class="fw-bold mb-1"><i class="ti ti-phone-incoming me-2" style="color:var(--brand-accent);"></i>Calls</h4>
    <p class="text-muted mb-0">Every call the voice provider handed to this platform, and what happened to it. Digits are never stored, only how many there were. Kept 30 days.
    @if($lastCall)Last call: {{ \Illuminate\Support\Carbon::parse($lastCall)->diffForHumans() }}.@endif</p>
</div>
<div class="alert alert-info">A call that is <strong>not in this list</strong> never reached the platform: the number the payer rang is not routed to it (check the provider's dashboard and, on a shared number, PakaPay's <code>VOICE_FORWARD_URL</code>), or the app showed a different number from the one registered under Voice numbers.</div>
<form class="row g-2 mb-3" method="get">
    <div class="col-md-3"><select name="tenant" class="form-select form-select-sm"><option value="">Any tenant</option>@foreach($tenants as $id => $n)<option value="{{ $id }}" @selected(request('tenant') == $id)>{{ $n }}</option>@endforeach</select></div>
    <div class="col-md-2"><select name="outcome" class="form-select form-select-sm"><option value="">Any outcome</option>@foreach(['accepted','rejected','denied','no_tenant','throttled','prompt'] as $o)<option @selected(request('outcome') === $o)>{{ $o }}</option>@endforeach</select></div>
    <div class="col-md-2"><input name="number" class="form-control form-control-sm" placeholder="Number rung" value="{{ request('number') }}"></div>
    <div class="col-auto form-check align-self-center"><input class="form-check-input" type="checkbox" name="hide_prompts" value="1" id="hp" @checked(request()->boolean('hide_prompts'))><label class="form-check-label" for="hp">Hide first-step prompts</label></div>
    <div class="col-auto"><button class="btn btn-sm btn-brand">Filter</button></div>
</form>
<div class="card"><div class="table-responsive"><table class="table mb-0">
<thead><tr><th>When</th><th>Number rung</th><th>Caller</th><th>Step</th><th>Digits</th><th>Result</th><th>Why</th></tr></thead><tbody>
@forelse($rows as $r)
<tr>
    <td>{{ $r->created_at->format('d M H:i:s') }}</td>
    <td>{{ $r->destination }}</td>
    <td>····{{ $r->caller_tail }}</td>
    <td>{{ $r->step }}</td>
    <td>{{ $r->digits_length ?? '·' }}</td>
    <td><span class="{{ ['accepted' => 'badge-success-soft', 'prompt' => 'badge-theme'][$r->outcome] ?? 'badge-danger-soft' }}">{{ $r->outcome }}</span></td>
    <td class="small">@if($r->reason)<code>{{ $r->reason }}</code> {{ $reasons[$r->reason] ?? '' }}@endif</td>
</tr>
@empty<tr><td colspan="7" class="text-muted">No calls logged yet.</td></tr>@endforelse
</tbody></table></div></div>
<div class="mt-3">{{ $rows->links() }}</div>
@endsection
