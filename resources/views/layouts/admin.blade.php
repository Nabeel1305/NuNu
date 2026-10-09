<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>@yield('title', 'Platform admin')</title>
<style>
  :root { --bg:#f7f7f5; --panel:#fff; --ink:#1c1c1a; --muted:#6b6b66; --line:#e2e2dc; --accent:#1f6feb; --bad:#b42318; --good:#1a7f37; --warn:#9a6700; }
  @media (prefers-color-scheme: dark) { :root { --bg:#121212; --panel:#1b1b1a; --ink:#ececea; --muted:#9a9a94; --line:#2c2c2a; --accent:#6ea8fe; --bad:#f97066; --good:#4ade80; --warn:#e3b341; } }
  * { box-sizing: border-box; }
  body { margin:0; background:var(--bg); color:var(--ink); font:15px/1.5 system-ui,-apple-system,Segoe UI,sans-serif; }
  header { display:flex; justify-content:space-between; align-items:center; padding:12px 24px; background:var(--panel); border-bottom:1px solid var(--line); }
  header a { color:var(--ink); text-decoration:none; font-weight:600; }
  main { max-width:960px; margin:0 auto; padding:24px 16px 64px; }
  h1 { font-size:22px; margin:0 0 16px; } h2 { font-size:16px; margin:0 0 12px; }
  section { background:var(--panel); border:1px solid var(--line); border-radius:10px; padding:16px; margin-bottom:16px; }
  table { width:100%; border-collapse:collapse; font-size:14px; } th,td { text-align:left; padding:6px 8px; border-bottom:1px solid var(--line); vertical-align:top; } th { color:var(--muted); font-weight:500; }
  label { display:block; margin:8px 0 4px; color:var(--muted); font-size:13px; }
  input,select { width:100%; padding:8px; border:1px solid var(--line); border-radius:6px; background:var(--bg); color:var(--ink); font:inherit; }
  input[type=checkbox] { width:auto; }
  button { padding:8px 14px; border:1px solid var(--line); border-radius:6px; background:var(--panel); color:var(--ink); font:inherit; cursor:pointer; }
  button.primary { background:var(--accent); border-color:var(--accent); color:#fff; } button.danger { color:var(--bad); }
  .row { display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:12px; }
  .muted { color:var(--muted); } .bad { color:var(--bad); } .good { color:var(--good); } .warn { color:var(--warn); }
  .flash { padding:10px 14px; border-radius:8px; border:1px solid var(--line); background:var(--panel); margin-bottom:16px; }
  .secret { border-color:var(--warn); } .secret code { display:block; margin-top:6px; padding:8px; background:var(--bg); border-radius:6px; word-break:break-all; user-select:all; }
  form.inline { display:inline; }
  @media (max-width:640px) { main { padding:16px 12px 48px; } th,td { padding:6px 4px; } }
</style>
</head>
<body>
<header>
  <a href="{{ route('admin.tenants.index') }}">Offline Payment Platform</a>
  @auth('admin')
    <form method="post" action="{{ route('admin.logout') }}">@csrf <a href="{{ route('admin.security') }}" class="muted" style="font-weight:400">Security</a> <span class="muted">{{ auth('admin')->user()->email }}</span> <button>Sign out</button></form>
  @endauth
</header>
<main>
  @if (session('secret'))
    <div class="flash secret"><strong>{{ session('secret')['label'] }}</strong> — copy it now, it will not be shown again.<code>{{ session('secret')['value'] }}</code></div>
  @endif
  @if (session('status'))<div class="flash">{{ session('status') }}</div>@endif
  @if ($errors->any())<div class="flash bad">@foreach ($errors->all() as $error)<div>{{ $error }}</div>@endforeach</div>@endif
  @yield('content')
</main>
</body>
</html>
