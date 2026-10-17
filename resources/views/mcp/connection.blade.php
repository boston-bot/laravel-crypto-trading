<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>Robinhood read-only connection</title></head>
<body><main>
<h1>Robinhood read-only connection</h1>
<p>Integration: {{ $enabled ? 'enabled for manual connection checks' : 'disabled — configure ROBINHOOD_MCP_ENABLED first' }}.</p>
<p>Status: {{ $connection?->status ?? 'not connected' }}. {{ $connection?->error_code }}</p>
@if (session('mcp_error'))<p role="alert">Connection check failed: {{ session('mcp_error') }}. Reconnect if authorization has expired or been revoked.</p>@endif
@if (session('mcp_status'))<p role="status">{{ session('mcp_status') }}</p>@endif
<p>Robinhood currently advertises the “internal” authorization scope. Review its consent screen: broker permissions can include trading. This application's adapter permits only locally reviewed read operations. If onboarding requests a new brokerage account or changes to trading approvals, stop and review those separately.</p>
<form method="post" action="{{ route('mcp.connect') }}">@csrf<button @disabled(!$enabled)>Connect / reconnect with Robinhood</button></form>
@if ($connection)
<p>Account: {{ $connection->bound_account_id ? '…'.substr($connection->bound_account_id, -4) : 'not bound' }}. Role: {{ $connection->account_role ?? 'unassigned' }}.</p>
<form method="post" action="{{ route('mcp.renew') }}">@csrf<button @disabled(!$enabled)>Renew OAuth credentials</button></form>
<form method="post" action="{{ route('mcp.discover') }}">@csrf<button @disabled(!$enabled)>Discover tool schemas</button></form>
<form method="post" action="{{ route('mcp.accounts') }}">@csrf<button @disabled(!$enabled)>Read accounts and choose binding</button></form>
<p>Configured read operations: {{ implode(', ', $operations) }}. A changed broker schema blocks its affected reads until reviewed.</p>
@if ($connection->bound_account_id)
<h2>Manual read checks</h2>
<p>Position and order reads cover equities. Other asset classes are not included in these lists. Missing data or source times remain unknown.</p>
<ul>@foreach (['portfolio', 'positions', 'orders'] as $read)<li><a href="{{ route('mcp.read', $read) }}">{{ ucfirst($read) }}</a></li>@endforeach</ul>
@foreach (['instruments', 'quotes'] as $read)<form method="get" action="{{ route('mcp.read', $read) }}"><label>Equity symbol <input name="symbol" placeholder="Symbol" required pattern="[A-Z][A-Z0-9.\-]{0,14}"></label><button>Read {{ $read }}</button></form>@endforeach
@endif
@if ($connection->capability_manifest)
<details><summary>Local capability catalog for schema review</summary><pre>{{ json_encode($connection->capability_manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre></details>
@endif
@endif
<p>Automatic monitoring and all brokerage writes remain controlled separately and are not enabled by connecting.</p>
<form method="post" action="/logout">@csrf<button>Sign out</button></form>
</main></body></html>
