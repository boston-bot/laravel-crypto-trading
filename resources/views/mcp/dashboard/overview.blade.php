@php
    $o = $observations ?? [];
    $sleeves = $o['sleeve_exposure'] ?? null;
    $sleeveLabels = ['long_term' => 'Long-term', 'active' => 'Active', 'unclassified' => 'Unclassified'];
    $session = $o['session'] ?? ['status' => 'unknown'];
@endphp
<div class="stat-grid">
    <x-stat label="Account equity" icon="wallet" hint="Broker-reported account total"><x-money :value="$o['equity'] ?? null" /></x-stat>
    <x-stat label="Cash" icon="database" hint="Broker-reported"><x-money :value="$o['cash'] ?? null" /></x-stat>
    <x-stat label="Broker buying power" icon="shield" hint="Broker-reported; not reduced again"><x-money :value="$o['buying_power'] ?? null" /></x-stat>
    <x-stat label="Observed equity exposure" icon="holdings" hint="Excludes unknown sector/ETF overlap"><x-money :value="$o['equity_exposure'] ?? null" /></x-stat>
</div>

<div class="grid-2">
    <x-panel title="Sleeve exposure" subtitle="Unmapped holdings stay unclassified; no attribution is invented" icon="holdings">
        @if($sleeves)
            <div class="bar" role="img" aria-label="Exposure by sleeve">
                @foreach($sleeveLabels as $key => $label)
                    @php $share = App\Support\Mcp\DisplayFormat::share($sleeves[$key] ?? null, $o['equity_exposure'] ?? null); @endphp
                    @if($share !== null && $share !== '0.0')<span class="bar-seg seg-{{ $key }}" style="--w: {{ $share }}%" title="{{ $label }} {{ $share }}%"></span>@endif
                @endforeach
            </div>
            <ul class="legend">
                @foreach($sleeveLabels as $key => $label)
                    <li><span class="swatch seg-{{ $key }}" aria-hidden="true"></span><span>{{ $label }}</span><x-money :value="$sleeves[$key] ?? null" /></li>
                @endforeach
            </ul>
        @else
            <p class="unknown">Unknown until a complete observation with known values is published.</p>
        @endif
    </x-panel>

    <x-panel title="Pending commitments" subtitle="Remaining limit notional; future fees unknown" icon="clock">
        <dl class="kv">
            <dt>Pending buy limit notional</dt><dd><x-money :value="$o['pending_buy_limit_notional'] ?? null" /> <small class="muted">(future fees unknown)</small></dd>
            <dt>Total pending buy commitments</dt><dd><x-money :value="$o['pending_buy_commitments'] ?? null" /></dd>
            <dt>Unresolved orders</dt><dd class="num">{{ $o['unresolved_orders'] ?? 'Unknown' }}</dd>
        </dl>
        <p class="note">Broker buying power is not reduced again. Exposure excludes unknown sector/ETF overlap.</p>
    </x-panel>

    <x-panel title="Market session" subtitle="Calendar coverage is required; weekdays are not assumed." icon="calendar">
        <div class="session">
            <x-pill :value="$session['status']" class="pill-lg">Market session: {{ $session['status'] ?? 'Unknown' }}</x-pill>
            <dl class="kv">
                <dt>Date</dt><dd>{{ $session['date'] ?? 'Unknown' }}</dd>
                @isset($session['opens_at'])<dt>Opens</dt><dd><x-time :at="$session['opens_at']" format="H:i T" :relative="false" /></dd>@endisset
                @isset($session['closes_at'])<dt>Closes</dt><dd><x-time :at="$session['closes_at']" format="H:i T" :relative="false" /></dd>@endisset
                <dt>Source</dt><dd>{{ $session['source'] ?? 'Unknown' }}</dd>
            </dl>
        </div>
    </x-panel>

    <x-panel title="Pause reasons" subtitle="Conditions that keep gates closed" icon="pause">
        <ul class="reason-list">
            @forelse($o['pause_reasons'] ?? ['no_complete_snapshot'] as $reason)
                <li><x-icon name="alert" :size="15" /><span>{{ App\Support\Mcp\DisplayFormat::humanize($reason) }}</span><code>{{ $reason }}</code></li>
            @empty
                <li class="ok-row"><x-icon name="check" :size="15" /><span>None observed</span></li>
            @endforelse
        </ul>
    </x-panel>
</div>

<x-panel title="Recent observations" subtitle="Read-only broker observations for this account" icon="refresh" flush>
    <x-slot:actions><a class="btn btn-ghost btn-sm" href="{{ route('mcp.dashboard', ['page' => 'health']) }}">All runs<x-icon name="chevron" :size="14" /></a></x-slot:actions>
    <div class="table-wrap"><table class="data">
        <thead><tr><th>Status</th><th>Step</th><th>Error</th><th>Started</th></tr></thead>
        <tbody>
        @forelse($runs->take(6) as $run)
            <tr><td><x-pill :value="$run->status" /></td><td>{{ $run->step }}</td><td>@if($run->error_code)<code>{{ $run->error_code }}</code>@else<span class="muted">—</span>@endif</td><td><x-time :at="$run->started_at" /></td></tr>
        @empty
            <tr><td colspan="4" class="empty-cell">No runs recorded.</td></tr>
        @endforelse
        </tbody>
    </table></div>
</x-panel>
