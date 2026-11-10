@extends('layouts.admin')
@section('title', 'System health')
@section('content')
<div class="mb-4 d-flex justify-content-between align-items-start flex-wrap gap-2">
    <div><h4 class="fw-bold mb-1"><i class="ti ti-heartbeat me-2" style="color:var(--brand-accent);"></i>System health</h4>
    <p class="text-muted mb-0">@if($bad)<span class="badge-danger-soft">{{ $bad }} problem(s)</span> @endif @if($warn)<span class="badge-warning-soft">{{ $warn }} warning(s)</span> @endif @if(!$bad && !$warn)<span class="badge-success-soft">All good</span>@endif</p></div>
    <form method="post" action="{{ route('admin.health.verify-audit') }}">@csrf<button class="btn btn-brand-outline btn-sm">Check audit chains now</button></form>
</div>
<div class="card"><div class="table-responsive"><table class="table mb-0"><tbody>
@foreach($checks as $c)<tr><td style="width:14rem"><strong>{{ $c['label'] }}</strong></td><td style="width:6rem"><span class="{{ ['ok' => 'badge-success-soft', 'warn' => 'badge-warning-soft', 'fail' => 'badge-danger-soft'][$c['status']] }}">{{ $c['status'] }}</span></td><td>{{ $c['detail'] }}</td></tr>@endforeach
</tbody></table></div></div>
@endsection
