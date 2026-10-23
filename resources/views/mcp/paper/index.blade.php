@extends('layouts.console')
@section('title', 'Paper Trading')
@section('nav', 'paper')
@section('eyebrow', 'Forward paper · simulated funds')
@section('heading', 'Paper Trading')
@section('content')
<div class="page paper-dashboard">
    <div class="section-head paper-heading"><div><h2>Your strategy, on paper</h2><p>Follow the virtual portfolio, see each decision and find what is blocking entries.</p></div>
        <div class="experiment-actions"><a class="btn btn-secondary btn-sm" href="{{ request()->fullUrl() }}">Refresh</a><a class="btn btn-ghost btn-sm" href="{{ route('mcp.experiments.index') }}">Historical experiments →</a></div>
    </div>
    <p class="note">Simulated results only · updated <x-time :at="$asOf" :relative="false" />. This page reads stored data; refresh does not run a strategy cycle.</p>
    @if($errors->any())<x-callout tone="bad"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></x-callout>@endif
    @if(!$data)
        <x-callout tone="info" title="No paper portfolio for this account">Bind your agentic account and apply a paper policy in the <a href="{{ route('mcp.dashboard', ['page' => 'control']) }}">Control Center</a>. No virtual balance has been inferred from brokerage funds.</x-callout>
    @else
        <div class="paper-switches">
            <x-pill :tone="config('paper.signals_enabled') ? 'ok' : 'neutral'">Evaluations {{ config('paper.signals_enabled') ? 'on' : 'off' }}</x-pill>
            <x-pill :tone="config('paper.signal_handoff_enabled') ? 'ok' : 'neutral'">Paper handoff {{ config('paper.signal_handoff_enabled') ? 'on' : 'off' }}</x-pill>
            <x-pill :tone="config('paper.real_data_fills_enabled') ? 'ok' : 'neutral'">Paper fills {{ config('paper.real_data_fills_enabled') ? 'on' : 'off' }}</x-pill>
            <x-pill :tone="config('paper.intraday_warmup_enabled') ? 'info' : 'neutral'">Warm-up {{ config('paper.intraday_warmup_enabled') ? 'on' : 'off' }}</x-pill>
            <a href="{{ route('mcp.dashboard', ['page' => 'control']) }}">Settings and incident review →</a>
        </div>
        @php $current = $data['current']; $cadence = $data['cadence']; $measured = $cadence['status'] === 'measured'; @endphp
        <div class="stat-grid paper-stats">
            <article class="stat"><span class="stat-label">Current paper equity</span><strong class="stat-value"><x-money :value="$current['equity']" /></strong><span class="stat-sub">Cash + verified bid marks + dividend receivables</span></article>
            <article class="stat"><span class="stat-label">Available cash</span><strong class="stat-value"><x-money :value="$current['available']" /></strong><span class="stat-sub">Cash <x-money :value="$current['cash']" /> · reserved <x-money :value="$current['reserved']" /></span></article>
            <article class="stat"><span class="stat-label">Realized trade P&amp;L</span><strong class="stat-value"><x-money :value="$current['realized']" /></strong><span class="stat-sub">All-time ledger · recorded trading costs included</span></article>
            <article class="stat"><span class="stat-label">Open marked P&amp;L</span><strong class="stat-value"><x-money :value="$current['unrealized']" /></strong><span class="stat-sub">Unknown if any held position lacks a valid mark</span></article>
        </div>
        <p class="note">Current totals are account-wide, independent of the session/table filters. Equity includes reserved cash once. Dividend income <x-money :value="$current['dividends']" /> · receivable <x-money :value="$current['receivable']" /> · recorded fill fees <x-money :value="$current['fees']" /> (already reflected in the ledger). Marks exclude hypothetical sale fees.</p>
        @if($current['equity'] === null)<x-callout tone="warn" title="Current valuation is incomplete">A held position has a missing/stale quote, unresolved action or accounting block. Its cost basis has not been substituted as a market price.</x-callout>@endif
        <form method="get" action="{{ route('mcp.paper') }}" class="paper-filters">
            <label class="field">Activity session<input type="date" name="date" value="{{ $date }}" max="{{ now('America/New_York')->toDateString() }}" required></label>
            <label class="field">Ticker<input name="symbol" value="{{ request('symbol') }}" placeholder="All symbols" maxlength="16" autocapitalize="characters"></label>
            <label class="field">Order state<select name="state"><option value="">All states</option>@foreach(['open', 'partial', 'filled', 'cancelled', 'expired', 'rejected'] as $state)<option value="{{ $state }}" @selected(request('state') === $state)>{{ ucfirst($state) }}</option>@endforeach</select></label>
            <button class="btn btn-secondary" type="submit">Apply filters</button>
        </form>
        <p class="note">Session dates follow the New York exchange calendar; timestamps are Central. Ticker filters the tables. Order state filters orders only; session health and evaluation totals stay account-wide.</p>
        @include('mcp.paper.equity', ['chart' => $data['chart']])
        <div class="paper-two-col">
            <x-panel title="Session activity" subtitle="{{ $date }} · repeated evaluations are not distinct trades" icon="clock">
                <div class="paper-activity">
                    <div><strong>{{ number_format($data['evaluations']) }}</strong><span>evaluations</span></div>
                    <div><strong>{{ number_format($data['counts']['signal'] ?? 0) }}</strong><span>buy/sell signals</span></div>
                    <div><strong>{{ number_format($data['orders_count']) }}</strong><span>orders accepted</span></div>
                    <div><strong>{{ number_format($data['open_orders']) }}</strong><span>currently open orders</span></div>
                </div>
                <p class="note">Last session cycle <x-time :at="$data['last_cycle']?->recorded_at" :relative="false" /> · {{ $data['nominees'] }} current nominees · {{ $data['universe'] }} symbols in current data universe.</p>
                @if(!$data['evaluations'])<p class="muted">No evaluations recorded for this session under the current account authorization.</p>@endif
            </x-panel>
            <x-panel title="Why entries are waiting" subtitle="Top eight reasons across the selected session" icon="shield">
                <div class="paper-bars">@forelse($data['blockers'] as $bar)
                    <div class="paper-bar-row"><div><span>{{ App\Support\Mcp\DisplayFormat::humanize($bar['reason']) }}</span><strong>{{ number_format($bar['count']) }}</strong></div><div class="paper-bar-track"><span style="width: {{ $bar['width'] }}%"></span></div></div>
                @empty<p class="muted">No blocked or waiting evaluations recorded.</p>@endforelse</div>
            </x-panel>
        </div>
        <x-panel title="Collection and quote health" subtitle="Stored session evidence · usable quotes do not establish trading eligibility" icon="health">
            @if($measured)
                <div class="paper-health-grid">
                    <div><span class="mini-label">Session progress</span><strong>{{ ucfirst(str_replace('_', ' ', $cadence['session']['phase'])) }}</strong><small>{{ $cadence['session']['elapsed_slots'] }} / {{ $cadence['session']['expected_slots'] }} completed windows</small></div>
                    <div><span class="mini-label">Completed-window collection</span><strong>{{ $cadence['collection']['elapsed_slot_coverage_pct'] === null ? 'Unknown' : $cadence['collection']['elapsed_slot_coverage_pct'].'%' }}</strong><small>{{ $cadence['collection']['elapsed_slots_with_reads'] }} windows with readings</small></div>
                    <div><span class="mini-label">Quotes usable at recording</span><strong>{{ number_format($cadence['collection']['signal_quote_quality']['usable_observations']) }} / {{ number_format($cadence['collection']['observations']) }}</strong><small>Admission under current authorization</small></div>
                    <div><span class="mini-label">Quote ages within limit at evaluation</span><strong>{{ $cadence['strategy']['within_quote_max_age_pct'] === null ? 'Unknown' : $cadence['strategy']['within_quote_max_age_pct'].'%' }}</strong><small>{{ $cadence['strategy']['quote_max_age_seconds'] }} s limit · {{ $cadence['strategy']['unknown_quote_time_signals'] }} unknown source times</small></div>
                </div>
                <p class="note">Health covers the whole stored session for this binding. A day with a deployment may mix collection modes. No evaluation samples means unknown, even if collection is healthy. Enabled switches do not prove worker success.</p>
            @else<p class="muted">Session health unavailable: {{ App\Support\Mcp\DisplayFormat::humanize($cadence['reason']) }}.</p>@endif
            @if(($data['membership']['entry_pause_reason'] ?? null) !== null)<x-callout tone="warn">Entry pause: {{ App\Support\Mcp\DisplayFormat::humanize($data['membership']['entry_pause_reason']) }}</x-callout>@endif
        </x-panel>
        @include('mcp.paper.positions')
        @include('mcp.paper.orders')
        @include('mcp.paper.signals')
        <x-panel title="Reference and profile readiness" subtitle="Current universe · recorded evidence, not a new eligibility assessment" icon="research" flush id="paper-readiness">
            <div class="table-wrap"><table class="data"><thead><tr><th>Ticker / role</th><th>Listing</th><th>Daily history</th><th>SEC basis</th><th>Latest profile</th></tr></thead><tbody>
                @forelse($data['readiness'] as $row)<tr><td><strong>{{ $row['symbol'] }}</strong><small class="block muted">{{ $row['role'] }}</small></td><td>{{ ucfirst($row['listing']) }}</td><td>{{ $row['history'] }}</td><td>{{ App\Support\Mcp\DisplayFormat::humanize($row['classification'] ?? 'unclassified') }}</td><td>{{ ucfirst($row['profile']) }}<small class="block muted">{{ App\Support\Mcp\DisplayFormat::humanize($row['profile_reason'] ?? 'profile_missing') }} · <x-time :at="$row['profile_at']" :relative="false" /></small></td></tr>
                @empty<tr><td colspan="5" class="empty-cell">No symbols in the current data universe for this filter.</td></tr>@endforelse
            </tbody></table></div>{{ $data['readiness']->fragment('paper-readiness')->links('mcp.pagination') }}
            <p class="note paper-table-note">Data-only lists prepare evidence and cannot nominate entries. A recorded SEC basis or complete profile can still fail eligibility. Check the recorded evaluation for the applicable reasons.</p>
        </x-panel>
    @endif
</div>
@endsection
