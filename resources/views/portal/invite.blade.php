@extends('layouts.auth')
@section('title', 'Set up your access')
@section('theme', 'portal-theme')
@section('icon', 'user-plus')
@section('heading', 'Welcome')
@section('subheading', 'Choose a password for ' . $user->email . '. Use at least 12 characters.')
@section('content')
<form method="post" action="{{ route('portal.invitation.accept', $token) }}">
    @csrf
    <div class="mb-3">
        <label class="form-label" for="name">Your name</label>
        <input id="name" name="name" class="form-control" value="{{ old('name', $user->name) }}" required maxlength="120" autocomplete="name">
    </div>
    <div class="mb-3">
        <label class="form-label" for="password">Password</label>
        <input id="password" name="password" type="password" class="form-control" required minlength="12" autocomplete="new-password">
    </div>
    <div class="mb-4">
        <label class="form-label" for="password_confirmation">Repeat password</label>
        <input id="password_confirmation" name="password_confirmation" type="password" class="form-control" required minlength="12" autocomplete="new-password">
    </div>
    <button class="btn btn-brand">Create access</button>
</form>
<p class="text-center text-muted mt-3 mb-0" style="font-size:12.5px">Next you will set up an authenticator app for two-factor sign-in.</p>
@endsection
