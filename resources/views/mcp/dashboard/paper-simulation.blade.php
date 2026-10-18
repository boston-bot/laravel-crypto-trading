<x-panel title="Offline paper simulation" subtitle="Synthetic scenarios only. No broker-confirmed trades or measured investment performance." icon="wallet">
    <dl class="kv kv-grid">
        <dt>Offline simulator gate</dt><dd>{{ config('paper.offline_simulation_enabled') ? 'Enabled for explicit synthetic scenarios' : 'Disabled' }} · no scheduled execution</dd>
        <dt>Remaining historical cost basis</dt><dd><x-money :value="$simulation['cost_basis']" /></dd>
        <dt>Realized simulated P&amp;L</dt><dd><x-money :value="$simulation['realized_pnl']" /></dd>
        <dt>Simulated fees included in P&amp;L / basis</dt><dd><x-money :value="$simulation['fees']" /></dd>
        <dt>Current valuation / unrealized P&amp;L / total return</dt><dd>Unavailable — no current validated marks or benchmark series</dd>
    </dl>
    <p class="note">Instrument classification and displayed size remain synthetic assumptions. They do not verify real liquidity, eligibility or corporate actions. Proposal-linked orders use explicitly applied execution settings; the application rechecks and sizes each order behind a separate handoff gate.</p>
    <div class="table-wrap"><table class="data"><thead><tr><th>Synthetic instrument / sleeve</th><th>Quantity / reserved shares</th><th>Historical basis / realized P&amp;L</th></tr></thead><tbody>
    @forelse($simulation['positions'] as $position)
        <tr><td>{{ $position->symbol }} · {{ $position->sleeve }}<small class="block">{{ $position->blocked_reason }}</small></td><td><x-quantity :value="$position->quantity" /><small class="block">Reserved <x-quantity :value="$position->reserved_quantity" /></small></td><td><x-money :value="$position->cost_basis" /><small class="block"><x-money :value="$position->realized_pnl" /></small></td></tr>
    @empty
        <tr><td colspan="3" class="empty-cell">No synthetic paper positions recorded.</td></tr>
    @endforelse
    </tbody></table></div>
</x-panel>
<x-panel title="Synthetic orders and original rationale" subtitle="Accepted orders can remain open, partially filled, cancelled, expired or rejected." icon="clock" flush>
    <div class="table-wrap"><table class="data"><thead><tr><th>Order / state</th><th>Requested / filled</th><th>Bound / reserved cash</th><th>Immutable scenario evidence</th></tr></thead><tbody>
    @forelse($simulation['orders'] as $order)
        <tr><td>#{{ $order->id }} · {{ $order->symbol }} · {{ $order->side }}<small class="block">{{ $order->type }} · {{ $order->status }} · {{ $order->sleeve }}</small></td>
            <td><x-quantity :value="$order->quantity" /><small class="block">Filled <x-quantity :value="$order->filled_quantity" /></small></td>
            <td><x-money :value="$order->price_bound" /><small class="block">Reserved <x-money :value="$order->reserved_cash" /></small></td>
            <td><details><summary>Original intent / fill assumptions</summary><p>Accepted <x-time :at="$order->accepted_at" /> · expires <x-time :at="$order->expires_at" /></p><pre class="code-block">{{ json_encode(['intent' => $order->intent, 'assumptions' => $order->assumptions], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre></details></td></tr>
    @empty
        <tr><td colspan="4" class="empty-cell">No offline scenario orders recorded.</td></tr>
    @endforelse
    </tbody></table></div>{{ $simulation['orders']->links('mcp.pagination') }}
</x-panel>
<x-panel title="Synthetic fill and lifecycle evidence" subtitle="Bid/ask fills require a later source observation. Missing or unusable data leaves an explicit unfilled event." icon="clock" flush>
    <div class="table-wrap"><table class="data"><thead><tr><th>Order / event</th><th>Quantity / fill price</th><th>Cash movement / fee</th><th>Recorded evidence</th></tr></thead><tbody>
    @forelse($simulation['events'] as $event)
        <tr><td>#{{ $event->sim_order_id }} · {{ $event->kind }}<small class="block">{{ $event->reason ?? 'No rejection' }}</small></td>
            <td><x-quantity :value="$event->quantity" /><small class="block"><x-money :value="$event->price" /></small></td>
            <td><x-money :value="$event->cash_delta" /><small class="block">Fee <x-money :value="$event->fee" /></small></td>
            <td><x-time :at="$event->recorded_at" /><small class="block">Ledger v{{ $event->ledger_version }}</small><details><summary>Source times / observation / outcome</summary><pre class="code-block">{{ json_encode(['observation' => $event->observation?->snapshot, 'outcome' => $event->evidence], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre></details></td></tr>
    @empty
        <tr><td colspan="4" class="empty-cell">No synthetic lifecycle events recorded.</td></tr>
    @endforelse
    </tbody></table></div>{{ $simulation['events']->links('mcp.pagination') }}
</x-panel>
