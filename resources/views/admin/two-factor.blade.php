@extends('layouts.admin')
@section('title', 'Verification code')
@section('content')
<section style="max-width:420px;margin:48px auto">
  <h1>Verification code</h1>
  <p class="muted">Enter the 6-digit code from your authenticator app.</p>
  <form method="post" action="{{ route('admin.two-factor.verify') }}">
    @csrf
    <label for="code">Code</label>
    <input id="code" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="12" required autofocus>
    <p><button class="primary">Verify</button></p>
  </form>
  <p class="muted"><a href="{{ route('admin.login') }}">Start over</a></p>
</section>
@endsection
