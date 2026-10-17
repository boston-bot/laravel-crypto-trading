@php $notFresh = collect($freshness)->where('status', '!=', 'fresh')->count(); @endphp
<details class="panel disclosure" id="provenance" @if(in_array($page, ['overview', 'health'], true)) open @endif>
    <summary>
        <div class="panel-title"><span class="panel-icon"><x-icon name="database" :size="16" /></span><div><h3>Data provenance and freshness</h3><p>{{ count($freshness) }} {{ Illuminate\Support\Str::plural('read', count($freshness)) }} · @if(count($freshness))<span @class(['unknown' => $notFresh > 0])>{{ $notFresh }} not fresh</span>@else<span class="unknown">unknown until a complete observation</span>@endif · missing or stale data is unknown, not zero</p></div></div>
        <x-icon name="chevron" class="disclosure-chevron" />
    </summary>
    <div class="table-wrap"><table class="data">
        <thead><tr><th>Read / Coverage</th><th>Source time</th><th>Retrieved</th><th>Status</th></tr></thead>
        <tbody>
        @forelse($freshness as $name => $state)
            <tr>
                <td><code>{{ $name }}</code> <span class="muted">/ {{ $state['coverage'] }}</span><small class="muted block">{{ $state['source'] }}</small></td>
                <td><x-time :at="$state['source_as_of'] ?? null" /></td>
                <td><x-time :at="$state['retrieved_at'] ?? null" />@if(isset($state['attempted_at']))<small class="muted block">Attempted: <x-time :at="$state['attempted_at']" /></small>@endif</td>
                <td><x-pill :value="$state['status']" />@if(isset($state['error_code']))<small class="block"><code>{{ $state['error_code'] }}</code></small>@endif</td>
            </tr>
        @empty
            <tr><td colspan="4" class="empty-cell">Unknown until a complete observation is published.</td></tr>
        @endforelse
        </tbody>
    </table></div>
</details>
