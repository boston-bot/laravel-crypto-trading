<x-panel title="Open paper positions" subtitle="Current ledger · bid marks require fresh scoped quotes and action clearance" icon="holdings" flush>
    <div class="table-wrap"><table class="data paper-position-table"><thead><tr><th>Ticker / sleeve</th><th>Quantity</th><th>Average cost / basis</th><th>Bid mark / market value</th><th>Open marked P&amp;L</th><th>Stop evidence</th></tr></thead><tbody>
        @forelse($data['positions'] as $position)<tr>
            <td><strong>{{ $position['symbol'] }}</strong><small class="block muted">{{ $position['sleeve'] }} · {{ $position['managed'] ? 'Strategy paper' : 'Other simulation' }}</small></td>
            <td><x-quantity :value="$position['quantity']" /><small class="block muted">Reserved <x-quantity :value="$position['reserved']" /></small></td>
            <td><x-money :value="$position['average_cost']" /><small class="block muted">Basis <x-money :value="$position['basis']" /></small></td>
            <td><x-money :value="$position['mark']" /><small class="block">Value <x-money :value="$position['value']" /></small><small class="block muted"><x-time :at="$position['mark_at']" :relative="false" /></small></td>
            <td><x-money :value="$position['unrealized']" />@if($position['reason'])<small class="block unknown">{{ App\Support\Mcp\DisplayFormat::humanize($position['reason']) }}</small>@endif</td>
            <td><small class="block">Initial <x-money :value="$position['initial_stop']" /></small><small class="block">Recorded trail <x-money :value="$position['trailing_stop']" /></small></td>
        </tr>@empty<tr><td colspan="6" class="empty-cell">No open paper positions for this filter. Broker holdings are separate.</td></tr>@endforelse
    </tbody></table></div><p class="note paper-table-note">Average cost includes recorded basis costs. Stops are rule thresholds, not projected sale prices. An unavailable trail is not reconstructed by evaluating rules on this page.</p>
</x-panel>
