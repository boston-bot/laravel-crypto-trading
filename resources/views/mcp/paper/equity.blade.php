<x-panel title="Recorded session-opening equity" subtitle="Up to 30 stored exchange sessions through the selected date · gaps are unknown valuations" icon="performance">
    @if($chart['known'])
        <div class="paper-chart-caption"><span>Recorded range <x-money :value="$chart['min']" /> – <x-money :value="$chart['max']" /></span><span>{{ $chart['known'] }} known · {{ $chart['missing'] }} missing</span></div>
        <svg class="paper-equity-chart" viewBox="0 0 960 220" preserveAspectRatio="none" role="img" aria-labelledby="paper-chart-title paper-chart-description">
            <title id="paper-chart-title">Recorded paper opening equity</title><desc id="paper-chart-description">Each dot is a verified session-opening valuation. Lines stop at missing sessions. Exact values are listed below.</desc>
            <path d="M30 25H930 M30 110H930 M30 195H930" class="paper-chart-grid" />
            @foreach($chart['segments'] as $points)<polyline points="{{ $points }}" class="paper-chart-line" />@endforeach
            @foreach($chart['dots'] as $dot)<circle cx="{{ $dot['x'] }}" cy="{{ $dot['y'] }}" r="4" class="paper-chart-dot"><title>{{ $dot['date'] }} · {{ App\Support\Mcp\DisplayFormat::money($dot['equity']) }}</title></circle>@endforeach
        </svg>
        <div class="paper-chart-caption"><span>{{ $chart['series'][0]['date'] }}</span><span>{{ last($chart['series'])['date'] }}</span></div>
    @else<div class="empty"><x-icon name="performance" :size="22" /><p>No verified opening valuations yet. Current cash is shown above; an equity curve has not been invented from it.</p></div>@endif
    <details><summary>View recorded values</summary><div class="table-wrap"><table class="data"><thead><tr><th>Session</th><th>Opening equity</th><th>Evidence</th></tr></thead><tbody>
        @forelse($chart['series'] as $row)<tr><td>{{ $row['date'] }}</td><td><x-money :value="$row['equity']" /></td><td>{{ $row['reason'] ?? 'Verified recorded opening' }}</td></tr>@empty<tr><td colspan="3">No stored exchange sessions in this chart window.</td></tr>@endforelse
    </tbody></table></div></details>
    <p class="note">These are opening snapshots, not an intraday or closing-equity curve. Connecting known points does not describe movement between observations. No return, drawdown or benchmark has been inferred from this chart.</p>
</x-panel>
