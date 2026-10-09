@extends('layouts.admin')
@section('title', 'Security')
@section('content')
<h1>Your security</h1>
<section>
  <h2>Two-factor authentication</h2>
  @if ($enabled)
    <p class="good">On. You are asked for a code from your authenticator app each time you sign in.</p>
    <p class="muted">It cannot be switched off here. If you lose your phone, an operator with server access runs <code>php artisan admin:reset-2fa {{ $admin->email }}</code>.</p>
  @else
    @if ($required)<p class="warn">This deployment requires two-factor authentication. Finish setup to use the dashboard.</p>@endif
    <p>1. Add this account to an authenticator app (Google Authenticator, 1Password, Authy and others all work). Enter this key by hand:</p>
    <div class="flash secret"><code>{{ chunk_split($secret, 4, ' ') }}</code></div>
    <p class="muted">Or open this address on the phone that has the app: <code style="word-break:break-all">{{ $uri }}</code></p>
    <p>2. Enter the 6-digit code the app now shows, to prove it works.</p>
    <form method="post" action="{{ route('admin.security.confirm') }}">
      @csrf
      <label for="code">Code</label>
      <input id="code" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="12" required>
      <p><button class="primary">Turn on two-factor</button></p>
    </form>
  @endif
</section>
@endsection
