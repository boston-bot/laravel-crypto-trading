<x-panel title="Simulated trades and open positions" subtitle="One row per position episode, grouped from actual simulated fills. Prices exclude fees; P&L includes recorded fees. All times are Central." icon="orders" flush id="simulated-trades">
    <div class="table-wrap"><table class="data experiment-trades">
        <thead><tr><th>Ticker / state</th><th>Playbook / quantity</th><th>Buy price / bought at</th><th>Sell price / sold at</th><th>Net P&L / fees</th><th>Exit plan / latest mark</th><th>Trade reasons</th></tr></thead>
        <tbody>@forelse($trades as $trade)
            <tr>
                <td><strong>{{ $trade['symbol'] }}</strong><span class="block"><x-pill :tone="$trade['status'] === 'Closed' ? 'neutral' : 'info'">{{ $trade['status'] }}</x-pill></span>
                    @if($trade['status'] !== 'Closed' && $trade['quantity_sold'] !== '0')<small class="block muted">Partially sold</small>@endif</td>
                <td>{{ ucfirst($trade['setup'] ?? 'Unknown') }}<small class="block muted">Bought <x-quantity :value="$trade['quantity_bought']" /> · sold <x-quantity :value="$trade['quantity_sold']" /></small><small class="block">Remaining <x-quantity :value="$trade['quantity_remaining']" /></small></td>
                <td><strong><x-money :value="$trade['buy_price']" /></strong><small class="block"><x-time :at="$trade['bought_at']" :relative="false" format="Y-m-d H:i:s T" /></small>
                    @if($trade['last_bought_at'] !== $trade['bought_at'])<small class="block muted">Last fill <x-time :at="$trade['last_bought_at']" :relative="false" /></small>@endif<small class="muted">Quantity-weighted average</small></td>
                <td><strong><x-money :value="$trade['sell_price']" fallback="No sale" /></strong>
                    @if($trade['sold_at'])<small class="block"><x-time :at="$trade['sold_at']" :relative="false" format="Y-m-d H:i:s T" /></small>
                    @elseif($trade['last_sold_at'])<small class="block muted">Last partial sale <x-time :at="$trade['last_sold_at']" :relative="false" /></small><small class="block">Position still open</small>
                    @else<small class="block muted">Position still open</small>@endif
                    @if($trade['sell_price'])<small class="muted">Quantity-weighted average</small>@endif</td>
                <td><strong><x-money :value="$trade['net']" /></strong><small class="block muted">{{ $trade['status'] === 'Closed' ? 'Closed net' : 'Open marked' }}</small><small class="block">Fees <x-money :value="$trade['fees']" /></small></td>
                <td>@if($trade['status'] !== 'Closed')
                    <small class="block">Initial stop <x-money :value="$trade['initial_stop']" /></small><small class="block">Trailing stop <x-money :value="$trade['trailing_stop']" /></small>
                    <small class="block">Bid mark <x-money :value="$trade['mark']" /></small><small class="block muted"><x-time :at="$trade['mark_at']" :relative="false" /></small>
                    @else<span class="muted">Closed; actual sale shown</span>@endif</td>
                <td><small class="block"><strong>Entry:</strong> {{ App\Support\Mcp\DisplayFormat::humanize($trade['buy_reason']) }}</small>
                    <small class="block"><strong>Exit:</strong> {{ $trade['sell_reason'] ? App\Support\Mcp\DisplayFormat::humanize($trade['sell_reason']) : ($trade['pending_exit_reason'] ? 'Intent awaiting fill' : 'No recorded intent') }}</small>
                    @if($trade['pending_exit_reason'])<small class="block muted">Intent: {{ App\Support\Mcp\DisplayFormat::humanize($trade['pending_exit_reason']) }} · {{ App\Support\Mcp\DisplayFormat::humanize($trade['pending_exit_status']) }}</small>@endif
                    <details class="experiment-fill-details"><summary>{{ count($trade['fills']) }} fill records</summary>
                        <ul class="bullets">@foreach($trade['fills'] as $fill)<li>{{ $fill['side'] }} <x-quantity :value="$fill['quantity']" /> @ <x-money :value="$fill['price']" /> · <x-time :at="$fill['at']" :relative="false" format="Y-m-d H:i:s T" /> · fee <x-money :value="$fill['fee']" /></li>@endforeach</ul>
                    </details>
                </td>
            </tr>
        @empty<tr><td colspan="7" class="empty-cell">{{ $result ? 'No filled trades in this variant. Inspect decision observations and unfilled orders below.' : 'This variant has not completed. Trade data appears after replay and accounting verification pass.' }}</td></tr>@endforelse</tbody>
    </table></div>
    {{ $trades->fragment('simulated-trades')->links('mcp.pagination') }}
    <p class="note experiment-table-note">There is no fixed take-profit target. Stops are rule triggers, not projected fill prices. Open marked P&L includes any partial sales and excludes hypothetical terminal sale fees. Stops and marks describe the replay window, not current market prices.</p>
</x-panel>
