<!DOCTYPE html>
<html lang="en">
<head>
    <script src="{{ asset('assets/js/theme-init.js') }}"></script>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title') · PakaPay Offline Platform</title>
    <link rel="icon" type="image/png" href="{{ asset('assets/img/favicon.png') }}">
    <link rel="stylesheet" href="{{ asset('assets/css/bootstrap.min.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/icons/tabler-icons.min.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/css/pakapay-admin.css') }}?v={{ filemtime(public_path('assets/css/pakapay-admin.css')) }}">
    <link rel="stylesheet" href="{{ asset('assets/css/admin-extra.css') }}?v={{ filemtime(public_path('assets/css/admin-extra.css')) }}">
</head>
<body class="auth-body @yield('theme')">
<div class="auth-card">
    <div class="auth-mark"><i class="ti ti-@yield('icon', 'shield-lock')"></i></div>
    <h1>@yield('heading')</h1>
    <p class="sub">@yield('subheading', 'PakaPay Offline Platform · Operators only')</p>

    @if (session('status'))<div class="auth-alert" style="background:var(--success-bg);color:var(--success)">{{ session('status') }}</div>@endif
    @if ($errors->any())<div class="auth-alert" role="alert">@foreach ($errors->all() as $error)<div>{{ $error }}</div>@endforeach</div>@endif

    @yield('content')

    <div class="auth-foot">© {{ date('Y') }} PakaPay</div>
</div>
</body>
</html>
