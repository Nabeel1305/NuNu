@extends('layouts.admin')
@section('title', 'Password')
@section('content')
<div class="mb-4">
    <h4 class="fw-bold mb-1"><i class="ti ti-key me-2" style="color:var(--brand-accent);"></i>Your password</h4>
    <p class="text-muted mb-0">@if($forced)You were given a temporary password. Choose your own to continue.@else Change your password. Every other session of yours is signed out when you do.@endif</p>
</div>
<div class="card" style="max-width:520px">
    <div class="card-body">
        <form method="post" action="{{ route('admin.password.update') }}">
            @csrf @method('PUT')
            <label class="form-label" for="current_password">Current password</label>
            <input id="current_password" name="current_password" type="password" class="form-control mb-1 @error('current_password') is-invalid @enderror" required autocomplete="current-password">
            @error('current_password')<div class="invalid-feedback d-block mb-2">{{ $message }}</div>@enderror
            <label class="form-label mt-3" for="password">New password <span class="text-muted">(12+ characters, letters and numbers)</span></label>
            <input id="password" name="password" type="password" class="form-control mb-1 @error('password') is-invalid @enderror" required minlength="12" autocomplete="new-password">
            @error('password')<div class="invalid-feedback d-block mb-2">{{ $message }}</div>@enderror
            <label class="form-label mt-3" for="password_confirmation">Repeat it</label>
            <input id="password_confirmation" name="password_confirmation" type="password" class="form-control mb-3" required minlength="12" autocomplete="new-password">
            <button class="btn btn-brand">Change password</button>
        </form>
        @unless($forced)
        <hr>
        <form method="post" action="{{ route('admin.sign-out-everywhere') }}" data-confirm="Every other browser you are signed in on will be signed out." data-confirm-label="Sign out the others">@csrf<button class="btn btn-brand-outline btn-sm">Sign out my other sessions</button></form>
        @endunless
    </div>
</div>
@endsection
