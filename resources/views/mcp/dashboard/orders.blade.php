<x-callout tone="neutral" icon="info">Broker-reported equity orders, including manual activity. These are observations, not application order intents or decisions. Unresolved: <strong class="num">{{ $observations['unresolved_orders'] ?? 'Unknown' }}</strong>.</x-callout>

<x-panel title="Broker-reported orders" subtitle="From the latest complete snapshot" icon="orders" flush>
    <div class="table-wrap"><table class="data">
        <thead><tr><th>Symbol</th><th>State</th><th>Side</th><th>Type</th><th class="num">Quantity / Filled</th><th class="num">Price</th></tr></thead>
        <tbody>
        @forelse($snapshot?->payload['orders']['records'] ?? [] as $record)
            @php $order = $record['data']; @endphp
            <tr>
                <td><div class="instrument"><span class="ticker" aria-hidden="true">{{ substr($order['symbol'] ?? '?', 0, 1) }}</span><strong>{{ $order['symbol'] ?? 'Unknown' }}</strong></div></td>
                <td><x-pill :value="$order['status']" /></td>
                <td><span @class(['side', 'side-buy' => $order['side'] === 'buy', 'side-sell' => $order['side'] === 'sell'])>{{ $order['side'] }}</span></td>
                <td>{{ $order['order_type'] ?? 'Unknown' }}</td>
                <td class="num"><x-quantity :value="$order['quantity'] ?? null" /> / <x-quantity :value="$order['filled_quantity'] ?? null" /></td>
                <td class="num">@isset($order['price'])<x-money :value="$order['price']" />@else<span class="unknown">Unknown</span>@endisset</td>
            </tr>
        @empty
            <tr><td colspan="6" class="empty-cell">{{ $snapshot ? 'Complete equity order set is empty.' : 'Orders unknown.' }}</td></tr>
        @endforelse
        </tbody>
    </table></div>
</x-panel>

<div class="empty empty-soft"><x-icon name="lock" :size="22" /><p>Application decisions and order intents arrive with paper evaluation (M5) and separately enabled execution (M6). Nothing on this page can submit, cancel or approve an order.</p></div>
