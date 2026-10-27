@extends('layouts.console')
@section('title', 'Crypto Paper')
@section('nav', 'crypto-paper')
@section('eyebrow', 'Forward paper · simulated funds · 24/7')
@section('heading', 'Crypto Paper')
@section('content')
@php use App\Support\Mcp\DisplayFormat; @endphp
<div class="page paper-dashboard">
    <div class="section-head paper-heading"><div><h2>Crypto strategy, on paper</h2><p>The separate $400 Coinbase sleeve: positions, simulated orders, closed trades and what is keeping entries waiting.</p></div>
        <div class="experiment-actions"><a class="btn btn-secondary btn-sm" href="{{ request()->fullUrl() }}">Refresh</a><a class="btn btn-ghost btn-sm" href="{{ route('mcp.paper') }}">Equity paper →</a></div>
    </div>
    <p class="note">Simulated results only · updated <x-time :at="$asOf" :relative="false" />. This page reads stored data; refresh does not run a cycle. No crypto order can reach a venue from this application.</p>
    @if(!$data)
        <x-callout tone="info" title="No crypto paper sleeve">Create one with <code>php artisan mcp:crypto-policy</code>. No virtual balance is inferred from venue funds.</x-callout>
    @else
        @php $s = $data['switches']; $current = $data['current']; $cycle = $data['cycle']; $stats = $data['stats']; $progress = $data['progress']; @endphp
        <div class="paper-switches">
            <x-pill :tone="$s['collection'] ? 'ok' : 'neutral'">Collection {{ $s['collection'] ? 'on' : 'off' }}</x-pill>
            <x-pill :tone="$s['paper'] ? 'ok' : 'neutral'">Paper cycle {{ $s['paper'] ? 'on' : 'off' }}</x-pill>
            <x-pill :tone="$s['fee_refresh'] ? 'ok' : 'neutral'">Fee refresh {{ $s['fee_refresh'] ? 'on' : 'off' }}</x-pill>
            <x-pill :tone="$s['coinbase_writes'] || $s['robinhood_writes'] ? 'bad' : 'ok'">Venue writes {{ $s['coinbase_writes'] || $s['robinhood_writes'] ? 'ON' : 'off' }}</x-pill>
            <span class="muted">Policy v{{ $data['policy']['version'] }} · {{ $data['policy']['parameters'] }}</span>
        </div>
        <div class="stat-grid paper-stats">
            <article class="stat"><span class="stat-label">Current paper equity</span><strong class="stat-value"><x-money :value="$current['equity']" /></strong><span class="stat-sub">Started <x-money :value="$current['starting']" /> · return {{ $current['return_pct'] === null ? 'unknown' : $current['return_pct'].'%' }}</span></article>
            <article class="stat"><span class="stat-label">Available cash</span><strong class="stat-value"><x-money :value="$current['available']" /></strong><span class="stat-sub">Cash <x-money :value="$current['cash']" /> · reserved <x-money :value="$current['reserved']" /></span></article>
            <article class="stat"><span class="stat-label">Realized / open P&amp;L</span><strong class="stat-value"><x-money :value="$current['realized']" /></strong><span class="stat-sub">Open marked <x-money :value="$current['unrealized']" /> · fees paid <x-money :value="$current['fees']" /></span></article>
            <article class="stat"><span class="stat-label">Max drawdown</span><strong class="stat-value">{{ $current['max_drawdown_pct'] === null ? 'Unknown' : $current['max_drawdown_pct'].'%' }}</strong><span class="stat-sub">Daily closes plus current mark</span></article>
        </div>
        @if($current['equity'] === null)<x-callout tone="warn" title="Current valuation is incomplete">No fresh bid for {{ implode(', ', $current['missing_marks']) }}. Cost basis has not been substituted as a market price.</x-callout>@endif
        @if($cycle && $cycle['entry_pause'])<x-callout tone="warn" title="Entries paused">{{ DisplayFormat::humanize($cycle['entry_pause']) }}@if($cycle['latched']) (latched until the next 00:00 UTC)@endif. Exits continue.</x-callout>@endif
        <div class="paper-two-col">
            <x-panel title="Observation progress" subtitle="Judge results only after both targets" icon="clock">
                <div class="paper-activity">
                    <div><strong>{{ $progress['closed_trades'] }}{{ $progress['target_trades'] === null ? '' : ' / '.$progress['target_trades'] }}</strong><span>closed trades</span></div>
                    <div><strong>{{ $progress['days'] }} / {{ $progress['target_days'] }}</strong><span>days observed</span></div>
                    <div><strong>{{ $stats['hit_rate_pct'] === null ? '—' : $stats['hit_rate_pct'].'%' }}</strong><span>hit rate</span></div>
                    <div><strong>{{ $stats['profit_factor'] ?? '—' }}</strong><span>profit factor</span></div>
                </div>
                <p class="note">Net after fees <x-money :value="$stats['net']" /> · average win {{ $stats['average_win_pct'] ?? '—' }}% · average loss {{ $stats['average_loss_pct'] ?? '—' }}%. Buy-and-hold since {{ $data['benchmarks']['since'] }}: BTC {{ $data['benchmarks']['btc_pct'] === null ? 'unknown' : $data['benchmarks']['btc_pct'].'%' }} · equal weight of {{ $data['benchmarks']['products'] }} listed coins {{ $data['benchmarks']['equal_weight_pct'] === null ? 'unknown' : $data['benchmarks']['equal_weight_pct'].'%' }}.</p>
            </x-panel>
            <x-panel title="Why entries are waiting" subtitle="Evaluations over the last 24 hours" icon="shield">
                @php $activity = $data['activity']; @endphp
                <p class="note">{{ number_format($activity['cycles']) }} of {{ $activity['expected_cycles'] }} five-minute cycles · {{ number_format($activity['evaluations']) }} evaluations · {{ number_format($activity['counts']['signal'] ?? 0) }} signals · unavailable {{ $activity['unavailable_pct'] ?? '—' }}%</p>
                <div class="paper-bars">@forelse($activity['reasons'] as $bar)
                    <div class="paper-bar-row"><div><span>{{ DisplayFormat::humanize($bar['reason']) }}</span><strong>{{ number_format($bar['count']) }}</strong></div><div class="paper-bar-track"><span style="width: {{ $bar['width'] }}%"></span></div></div>
                @empty<p class="muted">No evaluations recorded in the last 24 hours.</p>@endforelse</div>
            </x-panel>
        </div>
        <x-panel title="Daily equity" subtitle="UTC daily closes since the sleeve opened, then the current mark · gaps are unknown valuations" icon="performance">
            @php $chart = $data['curve']['chart']; @endphp
            @if($chart['dots'])
                <div class="paper-chart-caption"><span>Range <x-money :value="$chart['min']" /> – <x-money :value="$chart['max']" /></span><span>{{ $data['curve']['known'] }} known points</span></div>
                <svg class="paper-equity-chart" viewBox="0 0 960 220" preserveAspectRatio="none" role="img" aria-labelledby="crypto-chart-title"><title id="crypto-chart-title">Crypto paper equity by UTC day</title>
                    <path d="M30 25H930 M30 110H930 M30 195H930" class="paper-chart-grid" />
                    @foreach($chart['segments'] as $points)<polyline points="{{ $points }}" class="paper-chart-line" />@endforeach
                    @foreach($chart['dots'] as $dot)<circle cx="{{ $dot['x'] }}" cy="{{ $dot['y'] }}" r="4" class="paper-chart-dot"><title>{{ $dot['date'] }} · {{ DisplayFormat::money($dot['equity']) }}</title></circle>@endforeach
                </svg>
            @else<div class="empty"><x-icon name="performance" :size="22" /><p>A curve needs at least two known points. Values so far are listed below.</p></div>@endif
            <details><summary>View values</summary><div class="table-wrap"><table class="data"><thead><tr><th>UTC day</th><th>Equity</th><th>Basis</th></tr></thead><tbody>
                @foreach($data['curve']['points'] as $point)<tr><td>{{ $point['date'] }}</td><td><x-money :value="$point['equity']" /></td><td>{{ $point['kind'] === 'current' ? 'Current bid marks' : 'Ledger at 24:00 UTC, stored daily close' }}</td></tr>@endforeach
            </tbody></table></div></details>
        </x-panel>
        <x-panel title="Open positions" subtitle="Bid marks from fresh stored Coinbase quotes" icon="holdings" flush>
            <div class="table-wrap"><table class="data"><thead><tr><th>Product</th><th>Quantity</th><th>Average cost / basis</th><th>Bid / value</th><th>Open P&amp;L</th><th>Stops</th></tr></thead><tbody>
                @forelse($data['positions'] as $row)<tr>
                    <td><strong>{{ $row['symbol'] }}</strong><small class="block muted">Since <x-time :at="$row['entry_at']" :relative="false" /> · {{ $row['days_held'] ?? '—' }} days</small></td>
                    <td><x-quantity :value="$row['quantity']" /><small class="block muted">Reserved <x-quantity :value="$row['reserved']" /></small></td>
                    <td><x-money :value="$row['average_cost']" :scale="$row['scale']" /><small class="block muted">Basis <x-money :value="$row['basis']" /></small></td>
                    <td><x-money :value="$row['mark']" :scale="$row['scale']" /><small class="block">Value <x-money :value="$row['value']" /></small></td>
                    <td><x-money :value="$row['unrealized']" /></td>
                    <td><small class="block">Initial <x-money :value="$row['initial_stop']" :scale="$row['scale']" /></small><small class="block">Trailing <x-money :value="$row['trailing_stop']" :scale="$row['scale']" /></small></td>
                </tr>@empty<tr><td colspan="6" class="empty-cell">No open crypto paper positions.</td></tr>@endforelse
            </tbody></table></div><p class="note paper-table-note">Average cost includes the entry fee. Stops are rule thresholds checked every five minutes, not resting orders.</p>
        </x-panel>
        <x-panel title="Simulated orders" subtitle="Latest 25 · limit orders against the stored best bid/ask and its displayed size" icon="orders" flush>
            <div class="table-wrap"><table class="data"><thead><tr><th>Product / side</th><th>Status</th><th>Filled / ordered</th><th>Limit / average fill</th><th>Fees</th><th>Accepted</th></tr></thead><tbody>
                @forelse($data['orders'] as $order)<tr>
                    <td><strong>{{ $order['symbol'] }}</strong><small class="block muted">{{ $order['side'] }}</small></td>
                    <td><x-pill :tone="DisplayFormat::tone($order['status'])">{{ ucfirst($order['status']) }}</x-pill>@if($order['reason'])<small class="block muted">{{ DisplayFormat::humanize($order['reason']) }}</small>@endif</td>
                    <td><x-quantity :value="$order['filled']" /><small class="block muted">of <x-quantity :value="$order['quantity']" /></small></td>
                    <td><x-money :value="$order['bound']" :scale="$order['scale']" /><small class="block"><x-money :value="$order['average']" :scale="$order['scale'] + 2" fallback="No fill" /></small></td>
                    <td><x-money :value="$order['fees']" /></td>
                    <td><x-time :at="$order['accepted_at']" :relative="false" /></td>
                </tr>@empty<tr><td colspan="6" class="empty-cell">No simulated crypto orders yet.</td></tr>@endforelse
            </tbody></table></div>
        </x-panel>
        <x-panel title="Closed trades" subtitle="Round trips net of every recorded fee" icon="performance" flush>
            <div class="table-wrap"><table class="data"><thead><tr><th>Product</th><th>Opened → closed</th><th>Cost / proceeds</th><th>Net</th><th>Exit reason</th></tr></thead><tbody>
                @forelse($data['trades'] as $trade)<tr>
                    <td><strong>{{ $trade['symbol'] }}</strong></td>
                    <td><x-time :at="$trade['entry_at']" :relative="false" /><small class="block muted"><x-time :at="$trade['exit_at']" :relative="false" /></small></td>
                    <td><x-money :value="$trade['cost']" /><small class="block muted"><x-money :value="$trade['proceeds']" /></small></td>
                    <td><x-money :value="$trade['net']" /><small class="block muted">{{ $trade['return_pct'] ?? '—' }}%</small></td>
                    <td>{{ $trade['reason'] ? DisplayFormat::humanize($trade['reason']) : 'Unknown' }}</td>
                </tr>@empty<tr><td colspan="5" class="empty-cell">No closed trades yet.</td></tr>@endforelse
            </tbody></table></div>
        </x-panel>
        <div class="paper-two-col">
            <x-panel title="Latest cycle" subtitle="Most recent five-minute evaluation" icon="clock">
                @if($cycle)
                    <p class="note">Window <x-time :at="$cycle['at']" :relative="false" /> · recorded <x-time :at="$cycle['recorded_at']" :relative="false" /> · baseline {{ $cycle['baseline'] === 'complete' ? 'set' : DisplayFormat::humanize($cycle['baseline_reason'] ?? 'unavailable') }}@if($cycle['baseline_equity']) (<x-money :value="$cycle['baseline_equity']" />)@endif · {{ $cycle['occupied'] }} of {{ $data['policy']['owner']['max_open_positions'] }} slots used</p>
                    <div class="table-wrap"><table class="data"><thead><tr><th>Product</th><th>Result</th></tr></thead><tbody>
                        @foreach($cycle['signals'] as $signal)<tr><td>{{ $signal->symbol }}</td><td><x-pill :tone="$signal->status === 'signal' ? 'ok' : ($signal->status === 'wait' ? 'info' : 'neutral')">{{ ucfirst($signal->status) }}{{ $signal->side ? ' '.$signal->side : '' }}</x-pill> <small class="muted">{{ DisplayFormat::humanize($signal->reason) }}</small></td></tr>@endforeach
                    </tbody></table></div>
                @else<p class="muted">No crypto cycle recorded yet.</p>@endif
            </x-panel>
            <x-panel title="Costs and replay" subtitle="Fee tier in use and the latest retrospective replay" icon="research">
                @php $fee = $data['fees_observation']; $review = $data['policy']['fee_review']; $replay = $data['replay']; @endphp
                <p class="note">@if($fee)Coinbase tier {{ $fee->pricing_tier }} · maker {{ DisplayFormat::quantity((string) $fee->maker_rate) }} · taker {{ DisplayFormat::quantity((string) $fee->taker_rate) }} · read <x-time :at="$fee->retrieved_at" :relative="false" />@else No fee observation recorded.@endif
                    @if($review) · reviewed tier {{ $review['pricing_tier'] }}, {{ count($review['excluded_products']) }} excluded pairs, taxes {{ DisplayFormat::humanize($review['tax_treatment']) }}@endif. Sizing reserves the greater rate on both sides.</p>
                @if($replay)
                    @php $r = $replay->payload['result']; @endphp
                    <p class="note"><strong>Retrospective replay, not forward results.</strong> {{ $r['start'] }} → {{ $r['end'] }} ({{ $r['sessions'] }} days): return {{ $r['stats']['total_return_pct'] }}% · max drawdown {{ $r['stats']['max_drawdown_pct'] }}% · {{ $r['stats']['closed_trades'] }} trades · hit rate {{ $r['stats']['hit_rate_pct'] ?? '—' }}% · BTC buy-and-hold {{ $r['benchmarks']['benchmark_buy_and_hold_pct'] ?? '—' }}% · equal weight {{ $r['benchmarks']['equal_weight_universe_pct'] ?? '—' }}%.</p>
                    <p class="note">Daily bars approximate five-minute triggers and stops; today's coin list and fee tier are applied to the past.@if($replay->payload['excluded']) Excluded: {{ implode(', ', array_keys($replay->payload['excluded'])) }}.@endif</p>
                @else<p class="muted">No replay recorded. Run <code>php artisan mcp:crypto-replay</code>.</p>@endif
            </x-panel>
        </div>
    @endif
</div>
@endsection
