<!DOCTYPE html>
<html lang="en">
<head>
    <script src="{{ asset('assets/js/theme-init.js') }}"></script>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title', 'Portal') · {{ $tenant->name ?? 'PakaPay' }}</title>
    <link rel="icon" type="image/png" href="{{ asset('assets/img/favicon.png') }}">
    <link rel="stylesheet" href="{{ asset('assets/css/bootstrap.min.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/icons/tabler-icons.min.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/css/pakapay-admin.css') }}?v={{ filemtime(public_path('assets/css/pakapay-admin.css')) }}">
    <link rel="stylesheet" href="{{ asset('assets/css/admin-extra.css') }}?v={{ filemtime(public_path('assets/css/admin-extra.css')) }}">
    @stack('styles')
</head>
<body class="portal-theme">
<a href="#main" class="skip-link">Skip to content</a>
@php
    $me = auth('tenant')->user();
    $meInitial = strtoupper(mb_substr($me->name ?? 'U', 0, 1));
    $navGroups = [
        ['label' => null, 'items' => [
            ['label' => 'Overview', 'icon' => 'ti-layout-dashboard', 'route' => 'portal.dashboard', 'match' => ['portal.dashboard'], 'can' => null],
        ]],
        ['label' => 'Payments', 'items' => [
            ['label' => 'Transactions', 'icon' => 'ti-arrows-exchange', 'route' => 'portal.transactions.index', 'match' => ['portal.transactions.*'], 'can' => 'money'],
            ['label' => 'Payment codes', 'icon' => 'ti-hash', 'route' => 'portal.codes.index', 'match' => ['portal.codes.*'], 'can' => 'money'],
            ['label' => 'Subscribers', 'icon' => 'ti-users', 'route' => 'portal.subscribers', 'match' => ['portal.subscribers'], 'can' => 'money'],
            ['label' => 'Merchants', 'icon' => 'ti-building-store', 'route' => 'portal.merchants', 'match' => ['portal.merchants'], 'can' => 'money'],
        ]],
        ['label' => 'Developers', 'items' => [
            ['label' => 'API keys', 'icon' => 'ti-key', 'route' => 'portal.keys', 'match' => ['portal.keys*'], 'can' => 'developer_view'],
            ['label' => 'Webhooks', 'icon' => 'ti-webhook', 'route' => 'portal.webhooks', 'match' => ['portal.webhooks*'], 'can' => 'developer_view'],
            ['label' => 'Deliveries', 'icon' => 'ti-send', 'route' => 'portal.deliveries', 'match' => ['portal.deliveries*'], 'can' => 'developer_view'],
        ]],
        ['label' => 'Organisation', 'items' => [
            ['label' => 'Audit log', 'icon' => 'ti-history', 'route' => 'portal.audit', 'match' => ['portal.audit*'], 'can' => 'audit'],
            ['label' => 'Team', 'icon' => 'ti-user-shield', 'route' => 'portal.team', 'match' => ['portal.team*'], 'can' => 'team'],
            ['label' => 'Settings', 'icon' => 'ti-settings', 'route' => 'portal.settings', 'match' => ['portal.settings'], 'can' => null],
            ['label' => 'My account', 'icon' => 'ti-shield-lock', 'route' => 'portal.account', 'match' => ['portal.account*'], 'can' => null],
        ]],
    ];
    $navActive = null; $navActiveGroup = null;
    foreach ($navGroups as &$g) {
        $g['items'] = array_values(array_filter($g['items'], fn ($i) => $i['can'] === null || $me->can($i['can'])));
        foreach ($g['items'] as $i) {
            if (! $navActive && request()->routeIs(...$i['match'])) { $navActive = $i; $navActiveGroup = $g['label']; }
        }
    }
    unset($g);
    $navGroups = array_values(array_filter($navGroups, fn ($g) => $g['items'] !== []));
    $crumbCurrent = trim($__env->yieldContent('breadcrumb')) ?: ($navActive['label'] ?? 'Overview');
    $crumbIsChild = $navActive && $crumbCurrent !== $navActive['label'];
@endphp

<div class="sidebar-overlay" id="sidebarOverlay"></div>

<aside class="sidebar" id="sidebar" aria-label="Main navigation">
    <a class="sidebar-logo" href="{{ route('portal.dashboard') }}" aria-label="Portal home">
        <div class="sidebar-logo-icon">P</div>
        <div class="sidebar-logo-text">Paka<span>Pay</span></div>
    </a>
    <div class="sidebar-tenant"><small>Organisation</small><strong>{{ $tenant->name }}</strong></div>

    <nav class="sidebar-nav">
        @foreach($navGroups as $group)
            @if($group['label'])<div class="nav-section-title">{{ $group['label'] }}</div>@endif
            @foreach($group['items'] as $item)
                @php $isActive = $navActive && $navActive['route'] === $item['route']; @endphp
                <a href="{{ route($item['route']) }}" class="nav-item-link {{ $isActive ? 'active' : '' }}" @if($isActive) aria-current="page" @endif>
                    <i class="ti {{ $item['icon'] }}" aria-hidden="true"></i><span>{{ $item['label'] }}</span>
                </a>
            @endforeach
        @endforeach
    </nav>

    <div class="sidebar-footer">
        <div class="sidebar-user">
            <div class="sidebar-user-avatar" aria-hidden="true">{{ $meInitial }}</div>
            <div class="sidebar-user-info">
                <div class="sidebar-user-name">{{ $me->name }}</div>
                <div class="sidebar-user-role">{{ ucfirst($me->role) }}</div>
            </div>
            <form action="{{ route('portal.logout') }}" method="POST">@csrf
                <button type="submit" class="sidebar-logout" aria-label="Sign out" title="Sign out"><i class="ti ti-logout"></i></button>
            </form>
        </div>
    </div>
</aside>

<header class="app-header">
    <div class="header-left">
        <button class="header-menu-btn" data-open-sidebar aria-label="Open navigation"><i class="ti ti-menu-2"></i></button>
        <nav class="header-breadcrumb" aria-label="Breadcrumb">
            @if($navActiveGroup)<span>{{ $navActiveGroup }}</span><span class="sep">/</span>@endif
            @if($crumbIsChild)<a href="{{ route($navActive['route']) }}" class="crumb-link">{{ $navActive['label'] }}</a><span class="sep">/</span>@endif
            <strong aria-current="page">{{ $crumbCurrent }}</strong>
        </nav>
    </div>
    <div class="header-right">
        <span class="env-pill {{ $tenant->isLive() ? 'is-live' : 'is-sandbox' }}" title="{{ $tenant->isLive() ? 'Real payments' : 'Test mode: nothing here moves real money' }}">{{ $tenant->isLive() ? 'Live' : 'Sandbox' }}</span>
        <button type="button" class="icon-btn" id="themeToggle" aria-label="Switch to dark mode" title="Switch theme"><i class="ti ti-moon" aria-hidden="true"></i></button>
    </div>
</header>

<div id="progress-bar-container" aria-hidden="true"><div id="progress-bar" style="width:0%"></div></div>

<main class="page-wrapper" id="main">
    @unless($tenant->isActive())
        <div class="system-alert" role="alert"><div class="d-flex align-items-center gap-3"><i class="ti ti-alert-octagon"></i><div><strong>This organisation is suspended.</strong> New codes cannot be issued. Contact PakaPay support.</div></div></div>
    @endunless
    @if (session('secret'))
        <div class="card secret-card mb-4" role="status">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap">
                    <div><strong><i class="ti ti-key me-1"></i>{{ session('secret')['label'] }}</strong> — copy it now, it will not be shown again.</div>
                    <button type="button" class="btn btn-brand-outline btn-sm" data-copy="#secretValue"><i class="ti ti-copy me-1"></i>Copy</button>
                </div>
                <code id="secretValue">{{ session('secret')['value'] }}</code>
            </div>
        </div>
    @endif
    @yield('content')
</main>

<footer class="app-footer">© {{ date('Y') }} PakaPay · {{ $tenant->name }} portal</footer>

<nav class="mobile-bottom-nav" aria-label="Quick navigation">
    <a href="{{ route('portal.dashboard') }}" class="{{ request()->routeIs('portal.dashboard') ? 'active' : '' }}"><i class="ti ti-layout-dashboard"></i><span>Home</span></a>
    @if($me->can('money'))<a href="{{ route('portal.transactions.index') }}" class="{{ request()->routeIs('portal.transactions.*') ? 'active' : '' }}"><i class="ti ti-arrows-exchange"></i><span>Txns</span></a>@endif
    @if($me->can('developer_view'))<a href="{{ route('portal.webhooks') }}" class="{{ request()->routeIs('portal.webhooks*', 'portal.deliveries*') ? 'active' : '' }}"><i class="ti ti-webhook"></i><span>Webhooks</span></a>@endif
    <button type="button" data-open-sidebar><i class="ti ti-menu-2"></i><span>More</span></button>
</nav>

<div class="toast-stack" aria-live="polite">
    @if(session('status'))
        <div class="app-toast is-success" role="status" data-autohide="1"><i class="ti ti-circle-check" aria-hidden="true"></i><div class="app-toast-body">{{ session('status') }}</div><button type="button" class="app-toast-close" aria-label="Dismiss">&times;</button></div>
    @endif
    @if(session('tamper'))
        <div class="app-toast is-error" role="alert" data-autohide="0"><i class="ti ti-alert-circle" aria-hidden="true"></i><div class="app-toast-body">{{ session('tamper') }}</div><button type="button" class="app-toast-close" aria-label="Dismiss">&times;</button></div>
    @endif
    @foreach($errors->all() as $error)
        <div class="app-toast is-error" role="alert" data-autohide="0"><i class="ti ti-alert-circle" aria-hidden="true"></i><div class="app-toast-body">{{ $error }}</div><button type="button" class="app-toast-close" aria-label="Dismiss">&times;</button></div>
    @endforeach
</div>

<div class="modal fade" id="confirmModal" tabindex="-1" aria-labelledby="confirmTitle" aria-hidden="true" style="z-index:1090;">
    <div class="modal-dialog modal-dialog-centered" style="max-width:440px;">
        <div class="modal-content">
            <div class="modal-body text-center" style="padding:28px 28px 8px;">
                <div id="confirmIcon" class="confirm-icon" aria-hidden="true"><i class="ti ti-alert-triangle"></i></div>
                <h5 id="confirmTitle" class="mb-2" style="font-weight:600;">Are you sure?</h5>
                <p id="confirmMessage" class="text-muted mb-0"></p>
            </div>
            <div class="modal-footer justify-content-center border-0" style="padding:20px 28px 28px;gap:8px;">
                <button type="button" class="btn btn-brand-outline" data-bs-dismiss="modal">Go back</button>
                <button type="button" class="btn btn-danger" id="confirmOk">Confirm</button>
            </div>
        </div>
    </div>
</div>

<script src="{{ asset('assets/js/bootstrap.bundle.min.js') }}"></script>
<script src="{{ asset('assets/js/admin.js') }}?v={{ filemtime(public_path('assets/js/admin.js')) }}"></script>
@stack('scripts')
</body>
</html>
