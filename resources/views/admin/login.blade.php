@extends('layouts.auth')
@section('title', 'Sign in')
@section('heading', 'Platform Admin')
@section('content')
<form method="post" action="{{ route('admin.login') }}">
    @csrf
    <div class="mb-3">
        <label class="form-label" for="email">Email</label>
        <input id="email" name="email" type="email" class="form-control" value="{{ old('email') }}" required autofocus autocomplete="username">
    </div>
    <div class="mb-3">
        <label class="form-label" for="password">Password</label>
        <input id="password" name="password" type="password" class="form-control" required autocomplete="current-password">
    </div>
    <div class="form-check mb-4">
        <input class="form-check-input" type="checkbox" name="remember" value="1" id="remember">
        <label class="form-check-label" for="remember">Keep me signed in</label>
    </div>
    <button class="btn btn-brand">Sign in</button>
</form>
@endsection
