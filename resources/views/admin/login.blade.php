@extends('layouts.admin')
@section('title', 'Sign in')
@section('content')
<section style="max-width:420px;margin:48px auto">
  <h1>Sign in</h1>
  <form method="post" action="{{ route('admin.login') }}">
    @csrf
    <label for="email">Email</label>
    <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus autocomplete="username">
    <label for="password">Password</label>
    <input id="password" name="password" type="password" required autocomplete="current-password">
    <p><label style="display:inline"><input type="checkbox" name="remember" value="1"> Keep me signed in</label></p>
    <button class="primary">Sign in</button>
  </form>
</section>
@endsection
