@php
    $alertsConfigured = app(App\Services\Operations\NtfyNotifier::class)->configured();
    $heartbeatConfigured = app(App\Services\Operations\HeartbeatPing::class)->configured();
    $switches = [
        ['Monitoring scheduler', 'MCP_MONITORING_ENABLED', (bool) config('mcp.monitoring_enabled'), 'refresh', false],
        ['Legacy schedules', 'LEGACY_CRYPTO_SCHEDULES_ENABLED', (bool) config('mcp.legacy_schedules_enabled'), 'clock', true],
        ['Brokerage writes', 'BROKERAGE_WRITES_ENABLED', (bool) config('mcp.brokerage_writes_enabled'), 'lock', true],
        ['Research', 'MCP_RESEARCH_ENABLED', (bool) config('research.enabled'), 'spark', false],
        ['Operations', 'MCP_OPERATIONS_ENABLED', (bool) config('operations.enabled'), 'health', false],
    ];
    $commands = [
        ['php artisan mcp:doctor', 'Database identity and schema placement; read-only'],
        ['php artisan mcp:health --no-ping', 'Run operational checks now without pinging the heartbeat'],
        ['php artisan mcp:notify --test', 'Send a test alert to the configured ntfy topic'],
        ['php artisan mcp:summary --kind=daily', 'Summarize the previous closed New York day'],
        ['php artisan mcp:backup', 'Encrypted, verified schema backup with retention'],
        ['php artisan mcp:restore-drill', 'Restore into a scratch database, verify, then drop it'],
        ['php artisan mcp:import --file=<path> --dry-run', 'Preview and quarantine report for an archive import'],
    ];
@endphp

<div class="section-head"><div><h2>Operating switches</h2><p>Read from the private runtime configuration. They are deliberately not editable from the browser.</p></div></div>
<div class="switch-grid">
    @foreach($switches as [$label, $env, $on, $icon, $safeWhenOff])
        @php $tone = $on ? ($safeWhenOff ? 'bad' : 'ok') : ($safeWhenOff ? 'ok' : 'neutral'); @endphp
        <section class="switch switch-{{ $tone }}">
            <div class="switch-top"><span class="switch-icon"><x-icon :name="$icon" :size="16" /></span><x-pill :tone="$tone">{{ $on ? 'Enabled' : 'Disabled' }}</x-pill></div>
            <strong>{{ $label }}</strong>
            <code>{{ $env }}</code>
        </section>
    @endforeach
    <section class="switch switch-{{ $alertsConfigured ? 'ok' : 'warn' }}">
        <div class="switch-top"><span class="switch-icon"><x-icon name="bell" :size="16" /></span><x-pill :tone="$alertsConfigured ? 'ok' : 'warn'">{{ $alertsConfigured ? 'ntfy configured' : 'not configured' }}</x-pill></div>
        <strong>Phone alerts</strong>
        <code>{{ $pendingNotifications }} pending alert intents</code>
    </section>
    <section class="switch switch-{{ $heartbeatConfigured ? 'ok' : 'warn' }}">
        <div class="switch-top"><span class="switch-icon"><x-icon name="health" :size="16" /></span><x-pill :tone="$heartbeatConfigured ? 'ok' : 'warn'">{{ $heartbeatConfigured ? 'configured' : 'not configured' }}</x-pill></div>
        <strong>Heartbeat</strong>
        <code>MCP_HEALTHCHECKS_PING_URL</code>
    </section>
</div>

<div class="grid-2">
    <x-panel title="Research and queues" icon="spark">
        <p>Research: {{ config('research.enabled') ? 'Enabled' : 'Disabled' }}@if($research) · {{ $research['readiness'] ? 'unavailable ('.$research['readiness'].')' : 'ready' }}@endif · separate <code>mcp_research</code> queue; slow research cannot occupy monitoring workers.</p>
        <p class="note">An alert counts as delivered only when the provider acknowledges it. Sleeve policy, verified market calendar, source timestamps and flow history remain operator/provider prerequisites.</p>
    </x-panel>
    <x-panel title="Phone alerts" icon="bell">
        @if($alertsConfigured)
            <p>Reveal the private ntfy subscription values to connect the phone app. The topic stays off this page until you ask.</p>
            <form method="post" action="{{ route('mcp.operations.phone') }}">@csrf<button class="btn btn-secondary" type="submit"><x-icon name="bell" :size="16" />Show phone subscription setup</button></form>
        @else
            <p class="unknown">Phone alerts are not connected. A private ntfy topic is required before subscribing.</p>
        @endif
    </x-panel>
</div>

<x-panel title="Operational checks" subtitle="Evaluated when this page loads" icon="health" flush>
    <div class="table-wrap"><table class="data">
        <thead><tr><th>Check</th><th>Status</th><th>Detail</th></tr></thead>
        <tbody>
        @foreach($operations['checks'] as $name => $check)
            <tr><td><code>{{ $name }}</code></td><td><x-pill :value="$check['status']" /></td>
                <td><dl class="detail-list">@foreach($check['detail'] as $label => $value)<div><dt>{{ $label }}</dt><dd>{{ $value ?? 'unknown' }}</dd></div>@endforeach</dl></td></tr>
        @endforeach
        </tbody>
    </table></div>
</x-panel>

<div class="grid-2">
    <x-panel title="Alert deliveries" subtitle="Provider-acknowledged deliveries only count as delivered" icon="bell" flush>
        <div class="table-wrap"><table class="data">
            <thead><tr><th>Attempted</th><th>Kind</th><th>Status</th><th>Reference / error</th></tr></thead>
            <tbody>
            @forelse($operations['deliveries'] as $delivery)
                <tr><td><x-time :at="$delivery->attempted_at" /></td><td>{{ $delivery->kind }}</td><td><x-pill :value="$delivery->status" /></td><td><code class="id">{{ $delivery->provider_message_id ?? $delivery->error_code ?? '—' }}</code></td></tr>
            @empty
                <tr><td colspan="4" class="empty-cell">No delivery attempts recorded.</td></tr>
            @endforelse
            </tbody>
        </table></div>
    </x-panel>

    <x-panel title="Operator commands" subtitle="Run from the runtime app directory; not available as browser actions" icon="terminal">
        <ul class="commands">
            @foreach($commands as [$command, $purpose])
                <li><div><code>{{ $command }}</code><small class="muted">{{ $purpose }}</small></div><button type="button" class="icon-btn" data-copy="{{ $command }}" aria-label="Copy command"><x-icon name="copy" :size="15" /></button></li>
            @endforeach
        </ul>
    </x-panel>
</div>

<x-panel title="Observation runs" subtitle="Most recent 20 runs for this account" icon="refresh" flush>
    <div class="table-wrap"><table class="data">
        <thead><tr><th>Run UUID</th><th>Status / Step</th><th>Error</th><th>Started</th></tr></thead>
        <tbody>
        @forelse($runs as $run)
            <tr><td><code class="id">{{ $run->id }}</code></td><td><x-pill :value="$run->status" /> <span class="muted">{{ $run->step }}</span></td><td>@if($run->error_code)<code>{{ $run->error_code }}</code>@else<span class="muted">—</span>@endif</td><td><x-time :at="$run->started_at" /></td></tr>
        @empty
            <tr><td colspan="4" class="empty-cell">No runs recorded.</td></tr>
        @endforelse
        </tbody>
    </table></div>
</x-panel>
