@extends('layouts.admin')
@section('title', 'Platform settings')
@section('content')
<div class="mb-4"><h4 class="fw-bold mb-1"><i class="ti ti-adjustments me-2" style="color:var(--brand-accent);"></i>Platform settings</h4>
<p class="text-muted mb-0">Defaults for every tenant. A tenant's own setting (offline limits) still wins. Leave a field empty to use the built-in default shown beside it.</p></div>
<div class="card" style="max-width:820px"><div class="card-body">
<form method="post" action="{{ route('admin.settings.update') }}">
@csrf @method('PUT')
@foreach($rows as $r)
    @php $name = 's[' . str_replace('.', '_', $r['key']) . ']'; @endphp
    <div class="row g-2 align-items-center mb-3">
        <label class="col-md-7 col-form-label" for="{{ $name }}">{{ $r['label'] }}@if($r['kind'] === 'money') <span class="text-muted">(₦)</span>@endif</label>
        <div class="col-md-3"><input id="{{ $name }}" name="{{ $name }}" type="number" step="{{ $r['kind'] === 'money' ? '0.01' : '1' }}" min="0" class="form-control" value="{{ old('s.' . str_replace('.', '_', $r['key']), $r['saved']) }}" placeholder="{{ $r['default'] }}"></div>
        <div class="col-md-2 text-muted small">default {{ $r['default'] }}</div>
    </div>
@endforeach
@if($errors->any())<div class="alert alert-danger">{{ $errors->first() }}</div>@endif
<button class="btn btn-brand">Save</button>
</form></div></div>
@endsection
