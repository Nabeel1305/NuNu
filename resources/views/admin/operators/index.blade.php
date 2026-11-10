@extends('layouts.admin')
@section('title', 'Operators')
@section('content')
<div class="mb-4">
    <h4 class="fw-bold mb-1"><i class="ti ti-user-shield me-2" style="color:var(--brand-accent);"></i>Operators</h4>
    <p class="text-muted mb-0">Who can sign in to this console. <strong>Owner</strong>: everything. <strong>Support</strong>: view, view as a user, fix payments, retry webhooks, revoke devices. <strong>Finance</strong>: read only.</p>
</div>
<div class="card mb-4">
    <div class="table-responsive"><table class="table mb-0">
        <thead><tr><th>Name</th><th>Role</th><th>2FA</th><th>Last sign-in</th><th>Status</th><th></th></tr></thead>
        <tbody>
        @foreach($operators as $o)
            @php $me = $o->id === auth('admin')->id(); @endphp
            <tr>
                <td>{{ $o->name }}@if($me) <span class="badge-theme">you</span>@endif<br><small class="text-muted">{{ $o->email }}</small></td>
                <td>
                    @if($me)<span class="kv">{{ $o->role }}</span>
                    @else<form method="post" action="{{ route('admin.operators.role', $o) }}" class="d-inline-flex gap-1">@csrf @method('PATCH')
                        <select name="role" class="form-select form-select-sm" aria-label="Role of {{ $o->email }}">@foreach(\App\Models\Admin::ROLES as $r)<option value="{{ $r }}" @selected($o->role === $r)>{{ ucfirst($r) }}</option>@endforeach</select>
                        <button class="btn btn-sm btn-brand-outline">Set</button></form>@endif
                </td>
                <td>{{ $o->hasTwoFactor() ? 'on' : 'off' }}</td>
                <td>{{ $o->last_login_at?->diffForHumans() ?? 'never' }}</td>
                <td><span class="{{ $o->is_active ? 'badge-success-soft' : 'badge-danger-soft' }}">{{ $o->is_active ? 'active' : 'off' }}</span>@if($o->must_change_password) <span class="badge-warning-soft">temporary password</span>@endif</td>
                <td class="text-end">
                    <form class="d-inline" method="post" action="{{ route('admin.operators.reset-password', $o) }}" data-confirm="They get a new temporary password and are signed out." data-confirm-label="Reset password">@csrf<button class="btn btn-sm btn-brand-outline">New password</button></form>
                    @if($o->hasTwoFactor())<form class="d-inline" method="post" action="{{ route('admin.operators.reset-2fa', $o) }}" data-confirm="They will set up their authenticator again at next sign-in." data-confirm-label="Reset 2FA">@csrf<button class="btn btn-sm btn-brand-outline">Reset 2FA</button></form>@endif
                    @unless($me)
                    <form class="d-inline" method="post" action="{{ route('admin.operators.toggle', $o) }}">@csrf @method('PATCH')<button class="btn btn-sm btn-brand-outline">{{ $o->is_active ? 'Switch off' : 'Restore' }}</button></form>
                    <form class="d-inline" method="post" action="{{ route('admin.operators.destroy', $o) }}" data-confirm="{{ $o->email }} will be removed. Their past actions stay in the audit log." data-confirm-label="Remove">@csrf @method('DELETE')<button class="btn btn-sm btn-danger">Remove</button></form>
                    @endunless
                </td>
            </tr>
        @endforeach
        </tbody>
    </table></div>
    <div class="card-body border-top">
        <form method="post" action="{{ route('admin.operators.store') }}" class="row g-2 align-items-end">
            @csrf
            <div class="col-md-3"><label class="form-label" for="op_name">Name</label><input id="op_name" name="name" class="form-control" required maxlength="120"></div>
            <div class="col-md-4"><label class="form-label" for="op_email">Email</label><input id="op_email" name="email" type="email" class="form-control" required></div>
            <div class="col-md-2"><label class="form-label" for="op_role">Role</label><select id="op_role" name="role" class="form-select">@foreach(\App\Models\Admin::ROLES as $r)<option value="{{ $r }}" @selected($r === 'support')>{{ ucfirst($r) }}</option>@endforeach</select></div>
            <div class="col-auto"><button class="btn btn-brand">Add operator</button></div>
        </form>
        <p class="text-muted mt-3 mb-0">You get a one-time temporary password to pass to them. They must choose their own at first sign-in.</p>
    </div>
</div>
@endsection
