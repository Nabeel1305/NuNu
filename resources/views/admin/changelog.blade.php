@extends('layouts.admin')
@section('title', 'What changed')
@section('content')
<div class="mb-4"><h4 class="fw-bold mb-1"><i class="ti ti-news me-2" style="color:var(--brand-accent);"></i>What changed</h4></div>
<div class="card" style="max-width:820px"><div class="card-body">{!! $html !!}</div></div>
@endsection
