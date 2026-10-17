<x-panel title="Incident inbox" :subtitle="$openIncidents ? $openIncidents.' open · acknowledgement records the signed-in operator' : 'Acknowledgement records the signed-in operator'" icon="bell" id="incidents" flush>
    <ul class="incidents">
    @forelse($incidents as $incident)
        <li @class(['incident', 'incident-open' => $incident->state === 'open'])>
            <span class="incident-sev sev-{{ App\Support\Mcp\DisplayFormat::tone($incident->severity) }}" aria-hidden="true"></span>
            <div class="incident-main">
                <div class="incident-title"><x-pill :value="$incident->severity" /><strong>{{ App\Support\Mcp\DisplayFormat::humanize($incident->incident_key) }}</strong><code>{{ $incident->incident_key }}</code></div>
                <p class="muted">{{ $incident->state }} · {{ $incident->source }} · First <x-time :at="$incident->first_seen_at" /> · Last <x-time :at="$incident->last_seen_at" />@if($incident->resolved_at) · Resolved <x-time :at="$incident->resolved_at" />@endif @if($incident->reopen_count) · Recurred {{ $incident->reopen_count }}×@endif</p>
            </div>
            <div class="incident-actions">
                <x-pill :value="$incident->resolved_at ? 'resolved' : $incident->state" />
                @if($incident->state === 'open')<form method="post" action="{{ route('mcp.incident.ack', $incident->id) }}">@csrf<button class="btn btn-secondary btn-sm" type="submit"><x-icon name="check" :size="14" />Acknowledge</button></form>@endif
            </div>
        </li>
    @empty
        <li class="empty-cell">No incidents recorded. This does not establish data availability.</li>
    @endforelse
    </ul>
</x-panel>
