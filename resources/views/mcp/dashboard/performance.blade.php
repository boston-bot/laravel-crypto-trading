<x-panel title="Forward paper dashboard" subtitle="Virtual portfolio, executions, recorded evaluations and data readiness." icon="wallet">
    <a class="btn btn-secondary" href="{{ route('mcp.paper') }}">Open Paper Trading</a>
</x-panel>

<x-panel title="Historical simulation results" subtitle="Explore experiments separately from broker-confirmed performance." icon="performance">
    <a class="btn btn-secondary" href="{{ route('mcp.experiments.index') }}">View experiment comparisons and trades</a>
</x-panel>

<x-callout tone="warn" title="Return-dependent gates unavailable">Returns and drawdown gates unavailable: verified flow history and historical baseline are missing. Deposits are not gains. No historical, simulated, annualized or benchmark performance is inferred from current balances.</x-callout>

<div class="section-head"><div><h2>Daily and weekly summaries</h2><p>Deterministic summaries of closed periods only. No AI call.</p></div></div>
@forelse($research['summaries'] ?? [] as $summary)
    @php $s = $summary->payload; @endphp
    <article class="card summary-card">
        <header class="card-head">
            <div><strong class="card-title">{{ ucfirst($summary->kind) }} · {{ $summary->period }}</strong><small class="muted block">Generated <x-time :at="$summary->generated_at" /> · covers {{ $s['from'] }} to {{ $s['until'] }} · session {{ $s['session'] }}</small></div>
            <x-pill tone="neutral">{{ $summary->kind }}</x-pill>
        </header>
        <div class="mini-stats">
            <div><span class="mini-label">Observations</span><span class="mini-value">{{ $s['observations']['complete'] }} <small>complete</small></span><span @class(['mini-sub', 'unknown' => $s['observations']['failed'] > 0])>{{ $s['observations']['failed'] }} failed</span></div>
            <div><span class="mini-label">Incidents</span><span class="mini-value">{{ $s['incidents']['opened'] }} <small>opened</small></span><span class="mini-sub">{{ $s['incidents']['open'] }} open</span></div>
            <div><span class="mini-label">Research cost</span><span class="mini-value">{{ App\Services\Research\ResearchBudget::money($s['research']['actual_cost']) }}</span><span class="mini-sub">settled</span></div>
        </div>
        <p class="unknown">Performance: {{ $s['performance']['available'] ? 'available' : 'unavailable ('.$s['performance']['reason'].')' }} · Benchmark: unavailable ({{ $s['benchmark']['reason'] }})</p>
        <ul class="bullets">@foreach($s['limitations'] as $limitation)<li>{{ $limitation }}</li>@endforeach</ul>
    </article>
@empty
    <div class="empty"><x-icon name="performance" :size="22" /><p>No summaries generated. <code>php artisan mcp:summary --kind=daily</code> summarizes the previous closed New York day; open periods are never summarized.</p></div>
@endforelse
