@extends('layouts.admin')
@section('title', 'Tenants')
@section('content')
<h1>Tenants</h1>
<section>
  @if ($tenants->isEmpty())
    <p class="muted">No tenants yet. Create the first one below.</p>
  @else
  <table>
    <thead><tr><th>Name</th><th>Environment</th><th>Status</th><th>Voice</th><th>Keys</th><th>Pending</th></tr></thead>
    <tbody>
    @foreach ($tenants as $tenant)
      <tr>
        <td><a href="{{ route('admin.tenants.show', $tenant) }}">{{ $tenant->name }}</a><div class="muted">{{ $tenant->slug }}</div></td>
        <td>{{ $tenant->environment }}</td>
        <td class="{{ $tenant->status === 'active' ? 'good' : 'bad' }}">{{ $tenant->status }}</td>
        <td>{{ $tenant->voice_mode }}@if($tenant->voice_mode==='shared') <span class="muted">({{ $tenant->short_code }})</span>@endif</td>
        <td>{{ $tenant->active_keys_count }}</td>
        <td class="{{ ($pending[$tenant->id] ?? 0) > 0 ? 'warn' : 'muted' }}">{{ $pending[$tenant->id] ?? 0 }}</td>
      </tr>
    @endforeach
    </tbody>
  </table>
  @endif
</section>
<section>
  <h2>New tenant</h2>
  <p class="muted">Starts in the sandbox with one API key, shown once after you create it.</p>
  <form method="post" action="{{ route('admin.tenants.store') }}">
    @csrf
    <div class="row">
      <div><label for="name">Name</label><input id="name" name="name" required maxlength="120" value="{{ old('name') }}"></div>
      <div><label for="voice_mode">Voice number</label>
        <select id="voice_mode" name="voice_mode"><option value="own">Their own number</option><option value="shared">Shared pool</option></select></div>
    </div>
    <p><button class="primary">Create tenant</button></p>
  </form>
</section>
@endsection
