@extends('layouts.admin')
@section('title', 'Tenants')
@section('content')
<div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
    <div>
        <h4 class="fw-bold mb-1"><i class="ti ti-building me-2" style="color:var(--brand-accent);"></i>Tenants</h4>
        <p class="text-muted mb-0">Banks and fintechs that issue payment codes through the platform.</p>
    </div>
</div>

<div class="card mb-4">
    @if ($tenants->isEmpty())
        <div class="empty-state"><i class="ti ti-building-community"></i><h5>No tenants yet</h5><p class="mb-0">Create the first one below.</p></div>
    @else
    <div class="table-responsive">
        <table class="table">
            <thead><tr><th>Name</th><th>Environment</th><th>Status</th><th>Voice</th><th>Keys</th><th>Pending</th></tr></thead>
            <tbody>
            @foreach ($tenants as $tenant)
                <tr>
                    <td><a href="{{ route('admin.tenants.show', $tenant) }}" class="fw-semibold">{{ $tenant->name }}</a><div class="kv">{{ $tenant->slug }}</div></td>
                    <td><span class="{{ $tenant->environment === 'live' ? 'badge-info-soft' : 'badge-theme' }}">{{ $tenant->environment }}</span></td>
                    <td><span class="{{ $tenant->status === 'active' ? 'badge-success-soft' : 'badge-danger-soft' }}">{{ $tenant->status }}</span></td>
                    <td>{{ $tenant->voice_mode }}@if($tenant->voice_mode==='shared') <span class="kv">({{ $tenant->short_code }})</span>@endif</td>
                    <td>{{ $tenant->active_keys_count }}</td>
                    <td>@if(($pending[$tenant->id] ?? 0) > 0)<span class="badge-warning-soft">{{ $pending[$tenant->id] }}</span>@else<span class="text-muted">0</span>@endif</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
    @endif
</div>

<div class="card" style="max-width:720px">
    <div class="card-header"><span class="section-title mb-0 mt-0">New tenant</span></div>
    <div class="card-body">
        <p class="text-muted">Starts in the sandbox with one API key, shown once after you create it.</p>
        <form method="post" action="{{ route('admin.tenants.store') }}">
            @csrf
            <div class="row g-3 mb-3">
                <div class="col-md-7"><label class="form-label" for="name">Name</label><input id="name" name="name" class="form-control" required maxlength="120" value="{{ old('name') }}"></div>
                <div class="col-md-5"><label class="form-label" for="voice_mode">Voice number</label>
                    <select id="voice_mode" name="voice_mode" class="form-select"><option value="own">Their own number</option><option value="shared">Shared pool</option></select></div>
            </div>
            <button class="btn btn-brand"><i class="ti ti-plus me-1"></i>Create tenant</button>
        </form>
    </div>
</div>
@endsection
