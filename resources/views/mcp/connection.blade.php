@extends('layouts.console')
@section('title', 'Robinhood read-only connection')
@section('nav', 'connection')
@section('eyebrow', 'Brokerage · read only')
@section('heading', 'Robinhood read-only connection')

@section('content')
@php
    $status = $connection?->status ?? 'not connected';
    $authorized = $connection !== null;
    $discovered = (bool) $connection?->capability_manifest;
    $bound = (bool) $connection?->bound_account_id;
@endphp
@if (session('mcp_error'))<x-callout tone="bad" role="alert" title="Connection check failed">Connection check failed: <code>{{ session('mcp_error') }}</code>. Reconnect if authorization has expired or been revoked.</x-callout>@endif
@if (session('mcp_status'))<x-callout tone="ok" role="status" dismissible>{{ session('mcp_status') }}</x-callout>@endif

<div class="page">
<div class="grid-2">
    <x-panel title="Connection status" icon="connection">
        <div class="status-row">
            <x-pill :value="$status" class="pill-lg">Status: {{ $status }}</x-pill>
            <x-pill :tone="$enabled ? 'ok' : 'neutral'">{{ $enabled ? 'Integration enabled' : 'Integration disabled' }}</x-pill>
        </div>
        <dl class="kv">
            <dt>Integration</dt><dd>{{ $enabled ? 'enabled for manual connection checks' : 'disabled — configure ROBINHOOD_MCP_ENABLED first' }}.</dd>
            <dt>Account</dt><dd>@if($bound)<span class="mono">…{{ substr($connection->bound_account_id, -4) }}</span>@else<span class="unknown">not bound</span>@endif</dd>
            <dt>Role</dt><dd>{{ $connection?->account_role ?? 'unassigned' }}</dd>
            @if($connection?->error_code)<dt>Last error</dt><dd><code>{{ $connection->error_code }}</code></dd>@endif
        </dl>
    </x-panel>
    <x-callout tone="warn" icon="shield" title="Review the consent screen">Robinhood currently advertises the “internal” authorization scope. Review its consent screen: broker permissions can include trading. This application's adapter permits only locally reviewed read operations. If onboarding requests a new brokerage account or changes to trading approvals, stop and review those separately.</x-callout>
</div>

<div class="section-head"><div><h2>Setup</h2><p>Each step is read-only on the brokerage side. Connecting never enables monitoring or writes.</p></div></div>
<ol class="steps">
    <li @class(['step', 'step-done' => $authorized])>
        <span class="step-num">@if($authorized)<x-icon name="check" :size="15" />@else 1 @endif</span>
        <div class="step-body"><strong>Authorize with Robinhood</strong><p>Opens Robinhood's OAuth consent. Tokens are stored encrypted on this Mac.</p></div>
        <form method="post" action="{{ route('mcp.connect') }}">@csrf<button class="btn {{ $authorized ? 'btn-secondary' : 'btn-primary' }}" type="submit" @disabled(!$enabled)><x-icon name="external" :size="15" />Connect / reconnect with Robinhood</button></form>
    </li>
    @if ($connection)
    <li @class(['step', 'step-done' => $discovered])>
        <span class="step-num">@if($discovered)<x-icon name="check" :size="15" />@else 2 @endif</span>
        <div class="step-body"><strong>Discover tool schemas</strong><p>Saves the capability catalog. No brokerage tools are called.</p></div>
        <form method="post" action="{{ route('mcp.discover') }}">@csrf<button class="btn btn-secondary" type="submit" @disabled(!$enabled)><x-icon name="research" :size="15" />Discover tool schemas</button></form>
    </li>
    <li @class(['step', 'step-done' => $bound])>
        <span class="step-num">@if($bound)<x-icon name="check" :size="15" />@else 3 @endif</span>
        <div class="step-body"><strong>Bind an account</strong><p>Choose the eligible account for read-only monitoring.</p></div>
        <form method="post" action="{{ route('mcp.accounts') }}">@csrf<button class="btn btn-secondary" type="submit" @disabled(!$enabled)><x-icon name="wallet" :size="15" />Read accounts and choose binding</button></form>
    </li>
    <li class="step step-maint">
        <span class="step-num"><x-icon name="refresh" :size="15" /></span>
        <div class="step-body"><strong>Renew credentials</strong><p>Rotates OAuth tokens. Account binding and trading settings are not changed.</p></div>
        <form method="post" action="{{ route('mcp.renew') }}">@csrf<button class="btn btn-ghost" type="submit" @disabled(!$enabled)><x-icon name="refresh" :size="15" />Renew OAuth credentials</button></form>
    </li>
    @endif
</ol>

@if ($connection)
    <x-panel title="Configured read operations" subtitle="A changed broker schema blocks its affected reads until reviewed." icon="orders">
        <div class="chips">@foreach($operations as $operation)<span class="chip"><code>{{ $operation }}</code></span>@endforeach</div>
        <p class="note">Configured read operations: {{ implode(', ', $operations) }}.</p>
    </x-panel>

    @if ($bound)
    <x-panel title="Manual read checks" subtitle="Position and order reads cover equities. Other asset classes are not included in these lists. Missing data or source times remain unknown." icon="terminal">
        <div class="read-checks">
            <div class="read-links">
                @foreach (['portfolio', 'positions', 'orders'] as $read)<a class="btn btn-secondary btn-sm" href="{{ route('mcp.read', $read) }}" data-read>{{ ucfirst($read) }}</a>@endforeach
            </div>
            <form method="get" action="{{ route('mcp.watchlist.read') }}" class="inline-fields" data-read>
                <label class="field">Robinhood watchlist name<input name="name" value="On Watch" required maxlength="128"></label>
                <button class="btn btn-secondary btn-sm" type="submit" @disabled(!config('robinhood_mcp.watchlists_enabled'))>Read watchlist</button>
            </form>
            <p class="note">Watchlists belong to the authorized Robinhood user. Reading one does not grant trading eligibility or update paper permissions. @if(!config('robinhood_mcp.watchlists_enabled'))Watchlist reads are disabled in configuration.@endif</p>
            @foreach (array_merge(['instruments', 'quotes'], config('robinhood_mcp.execution_inputs_enabled') ? ['tradability', 'price_book'] : []) as $read)
                <form method="get" action="{{ route('mcp.read', $read) }}" class="inline-fields" data-read>
                    <label class="field">Equity symbol<input name="symbol" placeholder="Symbol" required pattern="[A-Z][A-Z0-9.\-]{0,14}" autocomplete="off" spellcheck="false"></label>
                    <button class="btn btn-secondary btn-sm" type="submit">Read {{ $read }}</button>
                </form>
            @endforeach
        </div>
        <div class="json-view" id="read-output" hidden>
            <div class="json-head"><span class="json-title">Result</span><button type="button" class="icon-btn" data-dismiss-json aria-label="Close result"><x-icon name="x" :size="15" /></button></div>
            <pre tabindex="0"></pre>
        </div>
    </x-panel>
    @endif

    @if ($connection->capability_manifest)
    <details class="panel disclosure">
        <summary><div class="panel-title"><span class="panel-icon"><x-icon name="database" :size="16" /></span><div><h3>Local capability catalog for schema review</h3><p>Discovered tool schemas and reviewed read-only mappings.</p></div></div><x-icon name="chevron" class="disclosure-chevron" /></summary>
        <div class="panel-body"><pre class="code-block">{{ json_encode($connection->capability_manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre></div>
    </details>
    @endif
@endif

<p class="note center">Automatic monitoring and all brokerage writes remain controlled separately and are not enabled by connecting.</p>
</div>
@endsection
