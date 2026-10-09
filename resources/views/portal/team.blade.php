@extends('layouts.portal')
@section('title', 'Team')
@section('content')
<div class="mb-4">
    <h4 class="fw-bold mb-1"><i class="ti ti-user-shield me-2" style="color:var(--brand-accent);"></i>Team</h4>
    <p class="text-muted mb-0">Who can sign in to {{ $tenant->name }}'s portal, and what they can do.</p>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-4"><div class="metric"><div class="label">Owner</div><div class="sub mt-1">Everything, including the team, and cancelling codes.</div></div></div>
    <div class="col-md-4"><div class="metric"><div class="label">Developer</div><div class="sub mt-1">API keys, webhooks and delivery logs. No payment amounts.</div></div></div>
    <div class="col-md-4"><div class="metric"><div class="label">Viewer</div><div class="sub mt-1">Read-only: payments, codes, parties, audit and webhook logs.</div></div></div>
</div>

<div class="card mb-4">
    <div class="table-responsive"><table class="table">
        <thead><tr><th>Person</th><th>Role</th><th>Status</th><th>2FA</th><th>Last sign-in</th><th class="text-end"></th></tr></thead>
        <tbody>
        @foreach($users as $u)
            @php $isMe = $u->is($me); $pendingInvite = isset($invited[$u->id]) && ! $u->hasAccepted(); @endphp
            <tr>
                <td><div class="fw-semibold">{{ $u->name }} @if($isMe)<span class="badge-theme ms-1">you</span>@endif</div><div class="kv">{{ $u->email }}</div></td>
                <td>
                    @if($isMe)
                        <span class="text-capitalize">{{ $u->role }}</span>
                    @else
                    <form method="post" action="{{ route('portal.team.role', $u->id) }}" class="d-flex gap-1">@csrf @method('PATCH')
                        <select name="role" class="form-select form-select-sm" style="width:130px" aria-label="Role for {{ $u->email }}">@foreach($roles as $r)<option value="{{ $r }}" @selected($u->role === $r)>{{ ucfirst($r) }}</option>@endforeach</select>
                        <button class="btn btn-sm btn-brand-outline">Save</button>
                    </form>
                    @endif
                </td>
                <td>
                    @if(! $u->hasAccepted())<span class="{{ $pendingInvite ? 'badge-warning-soft' : 'badge-danger-soft' }}">{{ $pendingInvite ? 'invited' : 'invite expired' }}</span>
                    @elseif($u->is_active)<span class="badge-success-soft">active</span>
                    @else<span class="badge-danger-soft">switched off</span>@endif
                </td>
                <td>{!! $u->hasTwoFactor() ? '<span class="badge-success-soft">on</span>' : '<span class="badge-warning-soft">off</span>' !!}</td>
                <td class="text-muted">{{ $u->last_login_at?->diffForHumans() ?? 'never' }}</td>
                <td class="text-end">
                    @unless($isMe)
                    <div class="d-inline-flex gap-1 flex-wrap justify-content-end">
                        <form method="post" action="{{ route('portal.team.access-link', $u->id) }}" data-confirm="This creates a link that lets {{ $u->email }} choose a new password. Any older link stops working." data-confirm-label="Create link" data-confirm-tone="primary">@csrf<button class="btn btn-sm btn-brand-outline" title="New access / password link"><i class="ti ti-link"></i></button></form>
                        @if($u->hasTwoFactor())<form method="post" action="{{ route('portal.team.reset-2fa', $u->id) }}" data-confirm="They will set up their authenticator app again at the next sign-in." data-confirm-label="Reset 2FA">@csrf<button class="btn btn-sm btn-brand-outline" title="Reset two-factor"><i class="ti ti-device-mobile-off"></i></button></form>@endif
                        <form method="post" action="{{ route('portal.team.toggle', $u->id) }}" data-confirm="{{ $u->is_active ? 'They will be signed out and unable to sign in.' : 'They will be able to sign in again.' }}" data-confirm-label="{{ $u->is_active ? 'Switch off' : 'Restore' }}" data-confirm-tone="{{ $u->is_active ? 'danger' : 'primary' }}">@csrf @method('PATCH')<button class="btn btn-sm btn-brand-outline" title="{{ $u->is_active ? 'Switch off' : 'Restore' }}"><i class="ti {{ $u->is_active ? 'ti-user-off' : 'ti-user-check' }}"></i></button></form>
                        <form method="post" action="{{ route('portal.team.destroy', $u->id) }}" data-confirm="Remove {{ $u->email }} permanently?" data-confirm-label="Remove">@csrf @method('DELETE')<button class="btn btn-sm btn-brand-outline" style="color:var(--danger)" title="Remove"><i class="ti ti-trash"></i></button></form>
                    </div>
                    @endunless
                </td>
            </tr>
        @endforeach
        </tbody>
    </table></div>
</div>

<div class="card" style="max-width:760px">
    <div class="card-header"><span class="section-title mb-0 mt-0">Invite someone</span></div>
    <div class="card-body">
        <form method="post" action="{{ route('portal.team.store') }}" class="row g-3">@csrf
            <div class="col-md-6"><label class="form-label" for="name">Name</label><input id="name" name="name" class="form-control" required maxlength="120" value="{{ old('name') }}"></div>
            <div class="col-md-6"><label class="form-label" for="email">Email</label><input id="email" name="email" type="email" class="form-control" required maxlength="191" value="{{ old('email') }}"></div>
            <div class="col-md-4"><label class="form-label" for="role">Role</label><select id="role" name="role" class="form-select">@foreach($roles as $r)<option value="{{ $r }}" @selected(old('role', 'viewer') === $r)>{{ ucfirst($r) }}</option>@endforeach</select></div>
            <div class="col-12"><button class="btn btn-brand"><i class="ti ti-user-plus me-1"></i>Create invitation link</button> <span class="text-muted ms-2" style="font-size:12.5px">You'll get a link to send them yourself. It works once and expires in 7 days.</span></div>
        </form>
    </div>
</div>
@endsection
