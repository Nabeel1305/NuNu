@extends('layouts.auth')
@section('title', 'Verification code')
@section('theme', 'portal-theme')
@section('icon', 'device-mobile-code')
@section('heading', 'Verification code')
@section('subheading', 'Enter the 6-digit code from your authenticator app.')
@section('content')
<form method="post" action="{{ route('portal.two-factor.verify') }}">
    @csrf
    <div class="mb-4">
        <label class="form-label" for="code">Code</label>
        <input id="code" name="code" class="form-control" inputmode="numeric" autocomplete="one-time-code" maxlength="12" required autofocus>
    </div>
    <button class="btn btn-brand">Verify</button>
</form>
<p class="text-center mt-3 mb-0"><a href="{{ route('portal.login') }}">Start over</a></p>
@endsection
