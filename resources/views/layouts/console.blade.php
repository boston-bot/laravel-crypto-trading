<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="dark light">
<meta name="referrer" content="no-referrer">
<title>@yield('title') · Investing Operations</title>
<script>try{var t=localStorage.getItem('ops-theme');if(t==='light'||t==='dark')document.documentElement.dataset.theme=t}catch(e){}</script>
@vite(['resources/css/console.css', 'resources/js/console.js'])
</head>
@php
    $active = trim($__env->yieldContent('nav'));
    $nav = ['overview' => 'overview', 'holdings' => 'holdings', 'research' => 'research', 'orders' => 'orders', 'performance' => 'performance', 'control' => 'shield', 'health' => 'health'];
    $connection ??= null;
    $writesEnabled = (bool) config('mcp.brokerage_writes_enabled');
    $monitoringEnabled = (bool) config('mcp.monitoring_enabled');
@endphp
<body>
<div class="shell" data-nav="">
<aside class="sidebar" id="sidebar" aria-label="Primary">
    <a class="brand" href="{{ route('mcp.dashboard') }}">
        <span class="brand-mark" aria-hidden="true"><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M6 15v4M6 5v6M12 12v7M12 4v4M18 9v10M18 3v2"/><rect x="4.5" y="10" width="3" height="5" rx="1" fill="currentColor"/><rect x="10.5" y="7.5" width="3" height="5" rx="1" fill="currentColor"/><rect x="16.5" y="5" width="3" height="5" rx="1" fill="currentColor"/></svg></span>
        <span class="brand-text"><strong>Investing Ops</strong><small>Local operator console</small></span>
    </a>
    <nav class="nav" aria-label="Dashboard">
        <p class="nav-label">Monitor</p>
        @foreach(App\Http\Controllers\MonitoringController::PAGES as $key => $label)
            <a href="{{ route('mcp.dashboard', ['page' => $key]) }}" class="nav-item" @if($active === $key) aria-current="page" @endif>
                <x-icon :name="$nav[$key]" />
                <span>{{ $label }}</span>
                @if($key === 'health' && ($openIncidents ?? 0) > 0)<span class="nav-badge" title="Open incidents">{{ $openIncidents }}</span>@endif
            </a>
        @endforeach
        <p class="nav-label">Brokerage</p>
        <a href="{{ route('mcp.connection') }}" class="nav-item" @if($active === 'connection') aria-current="page" @endif><x-icon name="connection" /><span>Broker connection</span></a>
    </nav>
    <div class="sidebar-foot">
        <div class="safety-card">
            <x-icon name="shield" :size="16" />
            <div><strong>{{ $writesEnabled ? 'Brokerage writes enabled' : 'Read-only monitoring' }}</strong><small>{{ $writesEnabled ? 'Review configuration immediately.' : 'No order, watchlist or approval writes.' }}</small></div>
        </div>
        @auth
        <div class="user-row">
            <span class="avatar" aria-hidden="true">{{ strtoupper(substr(auth()->user()->name ?: auth()->user()->email, 0, 1)) }}</span>
            <span class="user-meta"><strong>{{ auth()->user()->name }}</strong><small>{{ auth()->user()->email }}</small></span>
        </div>
        <div class="sidebar-actions">
            <button type="button" class="btn btn-ghost btn-sm" data-theme-toggle aria-label="Toggle color theme"><x-icon name="sun" class="theme-light" :size="16" /><x-icon name="moon" class="theme-dark" :size="16" /><span>Theme</span></button>
            <form method="post" action="/logout">@csrf<button class="btn btn-ghost btn-sm" type="submit"><x-icon name="logout" :size="16" /><span>Log out</span></button></form>
        </div>
        @endauth
    </div>
</aside>
<div class="scrim" data-nav-toggle aria-hidden="true"></div>
<div class="main">
    <header class="topbar">
        <button type="button" class="icon-btn nav-toggle" data-nav-toggle aria-controls="sidebar" aria-expanded="false" aria-label="Open navigation"><x-icon name="menu" /></button>
        <div class="topbar-title">
            <p class="eyebrow">@yield('eyebrow', 'Investing Operations')</p>
            <h1>@yield('heading')</h1>
        </div>
        <div class="topbar-status">
            @if($writesEnabled)
                <x-pill tone="bad"><x-icon name="alert" :size="13" />Brokerage writes enabled</x-pill>
            @else
                <x-pill tone="neutral" title="Brokerage writes are disabled in configuration"><x-icon name="lock" :size="13" />Execution disabled</x-pill>
            @endif
            <x-pill :tone="$monitoringEnabled ? 'ok' : 'neutral'">Monitoring {{ $monitoringEnabled ? 'on' : 'off' }}</x-pill>
            <span class="account-chip" title="Bound account role">
                <x-icon name="wallet" :size="14" />
                {{ $connection?->account_role ? App\Support\Mcp\DisplayFormat::humanize($connection->account_role) : 'Account unbound' }}@if($connection?->bound_account_id)<span class="mono">…{{ substr($connection->bound_account_id, -4) }}</span>@endif
            </span>
            @if($connection?->bound_account_id)
                <form method="post" action="{{ route('mcp.dashboard.sync') }}" class="inline-form">@csrf<input type="hidden" name="run_id" value="{{ (string) Illuminate\Support\Str::uuid() }}">
                    <button class="btn btn-primary btn-sm" type="submit" title="Read-only broker observation; publishes only a complete snapshot"><x-icon name="refresh" :size="15" /><span>Sync account</span></button>
                </form>
            @endif
        </div>
    </header>
    <main class="content" id="content">
        @yield('content')
    </main>
</div>
</div>
</body>
</html>
