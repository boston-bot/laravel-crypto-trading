<x-panel title="Paper orders and executions" subtitle="Orders accepted in the selected session · weighted prices come only from recorded fills" icon="orders" flush id="paper-orders">
    <div class="table-wrap"><table class="data paper-order-table"><thead><tr><th>Ticker / side / state</th><th>Requested / filled</th><th>Average fill / fees</th><th>Times (Central)</th><th>Bound / reason / fills</th></tr></thead><tbody>
        @forelse($data['orders'] as $row)@php $order = $row['order']; @endphp<tr>
            <td><strong>{{ $order->symbol }} · {{ $order->side }}</strong><small class="block"><x-pill :value="$order->status" /></small><small class="block muted">#{{ $order->id }} · {{ $order->type }} · {{ isset($order->intent['strategy_signal_id']) ? 'Forward paper' : 'Synthetic / other simulation' }}</small></td>
            <td><x-quantity :value="$order->quantity" /><small class="block muted">Filled <x-quantity :value="$row['quantity']" /></small></td>
            <td><strong><x-money :value="$row['average']" fallback="No fill" /></strong><small class="block muted">Fees <x-money :value="$row['fees']" /></small></td>
            <td><small class="block">Accepted <x-time :at="$order->accepted_at" :relative="false" /></small><small class="block">First fill <x-time :at="$row['fills']->first()?->recorded_at" fallback="No fill" :relative="false" /></small><small class="block">Last fill <x-time :at="$row['fills']->last()?->recorded_at" fallback="No fill" :relative="false" /></small></td>
            <td><small class="block">{{ $order->type === 'market' ? 'Simulation guard' : 'Limit bound' }} <x-money :value="$order->price_bound" /></small><small class="block">{{ $order->intent['rationale'] ?? 'No recorded rationale' }}</small>
                <details><summary>{{ $row['fills']->count() }} recorded fills</summary><ul class="bullets">@foreach($row['fills'] as $fill)<li><x-quantity :value="$fill->quantity" /> @ <x-money :value="$fill->price" /> · fee <x-money :value="$fill->fee" /> · recorded <x-time :at="$fill->recorded_at" :relative="false" /></li>@endforeach</ul></details>
            </td>
        </tr>@empty<tr><td colspan="5" class="empty-cell">No paper orders accepted in this session for these filters. See evaluations below for waiting or unavailable reasons.</td></tr>@endforelse
    </tbody></table></div>{{ $data['orders']->fragment('paper-orders')->links('mcp.pagination') }}
    <p class="note paper-table-note">Buy and sell orders have separate rows. Partial fills are quantity-weighted; acceptance time and price bounds are not fills. Market orders may fill on acceptance under their existing guard, while limit orders require a later observation. These are simulated executions, not brokerage confirmations.</p>
</x-panel>
