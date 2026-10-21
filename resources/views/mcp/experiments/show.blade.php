@extends('layouts.console')
@section('title', 'Experiment results')
@section('nav', 'experiments')
@section('eyebrow', 'Strategy research · historical simulation')
@section('heading', 'Experiment results')
@section('content')
@php $spec = $run->definition['spec']; $metrics = $progress['completed_variants'][$selected] ?? null; @endphp
<div class="page">
    <div class="section-head experiment-heading"><div><a href="{{ route('mcp.experiments.index') }}">← All experiments</a><h2>{{ $spec['name'] }}</h2><p>{{ $spec['hypothesis'] }}</p><small class="mono muted">{{ $run->experiment_id }}</small></div>
        <div class="experiment-actions"><a class="btn btn-secondary btn-sm" href="{{ request()->fullUrl() }}">Refresh results</a><a class="btn btn-ghost btn-sm" href="{{ route('mcp.experiments.report', ['experiment' => $run->experiment_id]) }}">JSON report</a></div>
    </div>
    @if($run->status === 'complete')<div><a class="btn btn-secondary" href="{{ route('mcp.experiments.index', ['reuse' => $run->experiment_id]) }}">New comparison with this dataset</a></div>@endif
    @if(session('experiment_status'))<x-callout tone="info" role="status">{{ session('experiment_status') }}</x-callout>@endif
    @if($errors->any())<x-callout tone="bad" title="Experiment unchanged"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></x-callout>@endif
    <div class="stat-grid">
        <div class="stat"><p class="stat-label">Progress</p><p class="stat-value"><x-pill :value="$run->status" /></p><p class="stat-hint">{{ count($progress['completed_steps']) }} / {{ $totalSteps }} steps completed</p></div>
        <div class="stat"><p class="stat-label">Historical window</p><p class="stat-value">{{ count($spec['sessions']) }} <small>sessions</small></p><p class="stat-hint">{{ $spec['sessions'][0] }} – {{ last($spec['sessions']) }}</p></div>
        <div class="stat"><p class="stat-label">Research universe</p><p class="stat-value">{{ implode(' · ', $spec['symbols']) }}</p><p class="stat-hint">{{ count($variants) }} variants · baseline {{ $spec['baseline'] }}</p></div>
        <div class="stat"><p class="stat-label">Selected variant</p><p class="stat-value"><x-money :value="$metrics['final_equity'] ?? null" fallback="Pending" /></p><p class="stat-hint">{{ $selected }} · {{ $metrics ? 'Final sampled equity' : 'Awaiting verified result' }}</p></div>
    </div>
    @if($run->reason)<x-callout tone="warn" title="Run status">{{ App\Support\Mcp\DisplayFormat::humanize($run->reason) }}. Completed checkpoints are retained.</x-callout>@endif
    @if($run->status !== 'complete')
    <x-panel title="Run controls" subtitle="The queue processes the experiment independently. Refresh to retrieve saved progress." icon="refresh">
        <p class="note">{{ $run->current_step ? 'Current step: '.$run->current_step : 'Waiting for the experiment worker.' }} @if($run->step_attempt) · attempt {{ $run->step_attempt }} @endif</p>
        @if($run->lease_expires_at?->isFuture())
            <p>A step holds an active lease until <x-time :at="$run->lease_expires_at" :relative="false" />. Resume is available after the lease ends.</p>
        @elseif($enabled)
            <form method="post" action="{{ route('mcp.experiments.resume', ['experiment' => $run->experiment_id]) }}">@csrf
                <input type="hidden" name="account_scope" value="{{ App\Services\Monitoring\AccountScope::key($connection) }}"><input type="hidden" name="auth_generation" value="{{ $connection->auth_generation }}">
                <button class="btn btn-secondary" type="submit">Resume unfinished work</button>
            </form>
        @else<p class="note">Historical processing is disabled in this checkout.</p>@endif
    </x-panel>
    @else
        <x-callout tone="info">Complete historical simulation. {{ $progress['report']['interpretation'] }}</x-callout>
    @endif
    <x-panel title="Variant comparison" subtitle="Every variant uses the same frozen dataset. Amounts are simulated; open marked P&L is not a closed-trade return." icon="performance" flush>
        <div class="table-wrap"><table class="data">
            <thead><tr><th>Variant</th><th class="num">Starting cash</th><th class="num">Final equity</th><th class="num">Return</th><th class="num">Drawdown</th><th class="num">Δ return vs baseline</th><th>Trades / open</th><th>Quote coverage</th><th></th></tr></thead>
            <tbody>@foreach($variants as $id => $variant)
                @php $m = $progress['completed_variants'][$id] ?? null; @endphp
                <tr @if($id === $selected) class="experiment-selected" @endif>
                    <td><strong>{{ $id }}</strong><small class="block muted">{{ $id === $spec['baseline'] ? 'Baseline' : 'Comparison' }}{{ $m ? ' · verified' : ' · pending' }}</small></td>
                    <td class="num"><x-money :value="$variant['equity']" /></td><td class="num"><x-money :value="$m['final_equity'] ?? null" :fallback="$m ? 'Unknown' : 'Pending'" /></td>
                    <td class="num">{{ $m['stats']['total_return_pct'] ?? ($m ? 'Unknown' : 'Pending') }}{{ isset($m['stats']['total_return_pct']) ? '%' : '' }}</td>
                    <td class="num">{{ $m['stats']['max_drawdown_pct'] ?? 'Pending' }}{{ isset($m['stats']['max_drawdown_pct']) ? '%' : '' }}</td>
                    @php $comparison = $progress['report']['comparisons'][$id] ?? null; @endphp
                    <td class="num">{{ $comparison['return_difference_percentage_points'] ?? ($comparison ? 'Unknown' : 'Pending') }}{{ isset($comparison['return_difference_percentage_points']) ? ' pp' : '' }}@if($comparison['capital_changed'] ?? false)<small class="block muted">Capital differs</small>@endif</td>
                    <td>{{ $m ? $m['stats']['closed_trades'].' closed / '.$m['open_positions'].' open' : 'Pending' }}</td><td>{{ $m ? $m['coverage']['known_quotes'].' / '.$m['coverage']['expected_quotes'] : 'Pending' }}</td>
                    <td><a class="btn btn-secondary btn-sm" href="{{ route('mcp.experiments.show', ['experiment' => $run->experiment_id, 'variant' => $id]) }}#simulated-trades" @if($id === $selected) aria-current="true" @endif>Inspect</a></td>
                </tr>
            @endforeach</tbody>
        </table></div>
    </x-panel>
    @if(isset($progress['report']['reference']))
    <x-panel title="Comparison with the previous experiment" subtitle="Same frozen observations; deltas are withheld when capital or settings differ." icon="clock" flush>
        <div class="table-wrap"><table class="data"><thead><tr><th>Variant</th><th>Reference baseline</th><th>Comparison</th><th class="num">Return difference</th><th class="num">Drawdown difference</th></tr></thead><tbody>
        @foreach($progress['report']['reference']['comparisons'] as $id => $comparison)<tr><td>{{ $id }}</td><td><a href="{{ route('mcp.experiments.show', ['experiment' => $comparison['reference_experiment']]) }}">{{ $comparison['reference_variant'] }}</a></td><td>{{ $comparison['comparable'] ? 'Capital and settings match' : 'Capital or settings differ' }}</td><td class="num">{{ $comparison['return_difference_percentage_points'] ?? 'Unavailable' }}{{ isset($comparison['return_difference_percentage_points']) ? ' pp' : '' }}</td><td class="num">{{ $comparison['drawdown_difference_percentage_points'] ?? 'Unavailable' }}{{ isset($comparison['drawdown_difference_percentage_points']) ? ' pp' : '' }}</td></tr>@endforeach
        </tbody></table></div>
    </x-panel>
    @endif
    <div class="section-head"><div><h2>{{ $selected }} · trade history</h2><p>Entry and exit prices are weighted from fills, with individual executions available in each row.</p></div></div>
    @include('mcp.experiments.trades')
    @if($metrics)
    <div class="grid-2">
        <x-panel title="Selected variant accounting" icon="wallet">
            <dl class="kv"><dt>Closed-trade net P&L</dt><dd><x-money :value="$metrics['closed_trade_net']" /></dd><dt>Open-episode marked P&L</dt><dd><x-money :value="$metrics['open_episode_marked_pnl']" /></dd><dt>Recorded fees</dt><dd><x-money :value="$metrics['fees']" /></dd><dt>Orders / fills</dt><dd>{{ $metrics['orders'] }} / {{ $metrics['fills'] }}</dd><dt>Assessment</dt><dd>{{ App\Support\Mcp\DisplayFormat::humanize($metrics['assessment']) }}</dd></dl>
        </x-panel>
        <x-panel title="Decision observations" subtitle="Repeated evaluations are observations, not distinct trades." icon="info">
            <dl class="kv">@foreach($metrics['event_reasons'] as $reason => $count)<dt>{{ App\Support\Mcp\DisplayFormat::humanize($reason) }}</dt><dd class="num">{{ $count }}</dd>@endforeach</dl>
        </x-panel>
    </div>
    @endif
    @if($result)
    <x-panel title="Order intents" subtitle="Limit bounds and accepted times are not fill prices or execution times." icon="orders" flush id="order-intents">
        <div class="table-wrap"><table class="data"><thead><tr><th>Ticker</th><th>Side / state</th><th>Quantity / filled</th><th>Limit bound</th><th>Accepted (Central)</th><th>Reason</th></tr></thead><tbody>
            @forelse($orderRows as $order)<tr><td>{{ $order['symbol'] }}</td><td>{{ $order['side'] }} · {{ App\Support\Mcp\DisplayFormat::humanize($order['status']) }}</td><td><x-quantity :value="$order['quantity']" /> / <x-quantity :value="$order['filled_quantity']" /></td><td><x-money :value="$order['limit']" /></td><td><x-time :at="$order['accepted_at']" :relative="false" /></td><td>{{ App\Support\Mcp\DisplayFormat::humanize($order['reason']) }}</td></tr>
            @empty<tr><td colspan="6" class="empty-cell">No order intents for this variant.</td></tr>@endforelse
        </tbody></table></div>
    {{ $orderRows->fragment('order-intents')->links('mcp.pagination') }}
    </x-panel>
    @endif
    @if($progress['failed_attempts'])<x-panel title="Failed attempt history" icon="clock"><ul class="bullets">@foreach($progress['failed_attempts'] as $failure)<li>{{ $failure['step_key'] }} · attempt {{ $failure['attempt'] }} · {{ App\Support\Mcp\DisplayFormat::humanize($failure['reason']) }}</li>@endforeach</ul></x-panel>@endif
    <x-panel title="Specification and evidence" icon="shield">
        <details><summary>Declared variants and parameters</summary><pre class="code-block">{{ json_encode($spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre></details>
        <p class="note">Definition fingerprint <code>{{ $run->definition_hash }}</code>. Historical earnings, eligibility, sampled quotes and opening-mark limitations remain part of the result.</p>
        @if($metrics)<details><summary>Research limitations</summary><ul class="bullets">@foreach($metrics['limitations'] as $limitation)<li>{{ App\Support\Mcp\DisplayFormat::humanize($limitation) }}</li>@endforeach</ul></details>@endif
    </x-panel>
</div>
@endsection
