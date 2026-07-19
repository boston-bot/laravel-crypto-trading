<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ ucfirst($page) }} | Trade Ops</title>
    @vite(['resources/css/app.css', 'resources/js/operations-console.js'])
</head>
@php
    $pages = [
        'overview' => ['label' => 'Overview', 'path' => '/dashboard', 'title' => 'What happened?', 'subtitle' => 'The latest strategy cycle, its evidence, and its outcome.'],
        'strategies' => ['label' => 'Strategies', 'path' => '/strategies', 'title' => 'Why this decision?', 'subtitle' => 'The latest action, its rule mechanics, counterfactual, and immutable evidence trail.'],
        'assets' => ['label' => 'Assets', 'path' => '/assets', 'title' => 'Asset attribution', 'subtitle' => 'P&L contribution and held-period return beside cost-exclusive buy-and-hold price context.'],
        'activity' => ['label' => 'Activity', 'path' => '/activity', 'title' => 'Decision trail', 'subtitle' => 'Evaluation, proposal, execution, and operational events in order.'],
        'paper' => ['label' => 'Paper', 'path' => '/paper', 'title' => 'Paper portfolio', 'subtitle' => 'Session capital, cash accounting, positions, and proposals.'],
        'operations' => ['label' => 'Operations', 'path' => '/operations', 'title' => 'Runtime operations', 'subtitle' => 'Workers, queues, cycles, data freshness, and safe controls.'],
        'research' => ['label' => 'Research', 'path' => '/research', 'title' => 'Research evidence', 'subtitle' => 'Data quality, calibration, backtests, and shadow spreads.'],
    ];
    $current = $pages[$page] ?? $pages['overview'];
@endphp
<body class="ops-console" data-console-page="{{ $page }}" data-asset-symbol="{{ $symbol ?? '' }}">
    <a class="ops-skip" href="#opsMain">Skip to main content</a>
    <div class="ops-frame">
        <aside class="ops-rail" aria-label="Primary navigation">
            <a class="ops-brand" href="/dashboard" aria-label="Trade Ops overview">
                <span class="ops-brand-mark">T/O</span>
                <span>TRADE / OPS</span>
            </a>
            <nav class="ops-nav">
                @foreach($pages as $key => $item)
                    <a href="{{ $item['path'] }}" @class(['active' => $page === $key]) @if($page === $key) aria-current="page" @endif>
                        <span class="ops-nav-index">0{{ $loop->iteration }}</span>
                        <span>{{ $item['label'] }}</span>
                    </a>
                @endforeach
            </nav>
            <div class="ops-rail-footer">
                <span class="ops-pulse" aria-hidden="true"></span>
                <span>LOCAL CONSOLE</span>
            </div>
        </aside>

        <div class="ops-workspace">
            <header class="ops-topbar">
                <div>
                    <p class="ops-eyebrow">{{ strtoupper($current['label']) }} · COINBASE FIRST</p>
                    <h1>{{ $current['title'] }}</h1>
                    <p>{{ $current['subtitle'] }}</p>
                </div>
                <div class="ops-top-status">
                    <span id="refreshState" class="ops-state-pill"><span class="ops-pulse" aria-hidden="true"></span> Connecting</span>
                    <span id="lastUpdated">Not updated</span>
                </div>
            </header>

            <main id="opsMain" class="ops-main" tabindex="-1">
                <div id="consoleAlert" class="ops-alert" role="alert" hidden></div>
                <div id="consoleContent" class="ops-loading" aria-live="polite" aria-busy="true">
                    <div class="ops-skeleton ops-skeleton-hero"></div>
                    <div class="ops-skeleton-grid"><div class="ops-skeleton"></div><div class="ops-skeleton"></div></div>
                </div>
            </main>
        </div>
    </div>
    <div id="opsToast" class="ops-toast" role="status" aria-live="polite" hidden></div>
</body>
</html>
