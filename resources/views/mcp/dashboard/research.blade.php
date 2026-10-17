@if(!$research)
    <x-callout tone="warn" title="Research unavailable">Bind an account before recording research. No research is available.</x-callout>
@else
@php
    $money = fn ($v) => App\Services\Research\ResearchBudget::money($v);
    $budget = $research['budget'];
    $pct = $budget['used_pct'] ?? null;
    $pctValid = is_string($pct) && preg_match('/^\d+(?:\.\d+)?$/D', $pct);
@endphp
<div class="status-row">
    <x-pill :tone="$research['readiness'] ? 'bad' : 'ok'" class="pill-lg">Research: {{ $research['readiness'] ? 'unavailable ('.$research['readiness'].')' : 'ready' }}</x-pill>
    <span class="meta-chip">Provider {{ config('research.provider') }}</span>
    <span class="meta-chip">Model {{ config('research.model') ?: 'not configured' }}</span>
    <span class="meta-chip">Prompt {{ App\Services\Research\ResearchPrompt::VERSION }}</span>
    <span class="muted">Monitoring and risk checks continue without research.</span>
</div>

<x-callout tone="info" icon="shield" title="Advisory only">Research proposals are non-executable notes for operator review. No order intent, order review, watchlist edit or broker request is created, and model output cannot change policy, limits, mode or credentials.</x-callout>

<div class="grid-2">
    <x-panel :title="'AI and data budget · '.$budget['period']" subtitle="Application spending ledger, not an invoice; provider billing can lag." icon="wallet">
        @if($budget['reason'])
            <p class="unknown">Budget unavailable: {{ $budget['reason'] }}. Model calls stay blocked until reviewed cost settings exist.</p>
        @else
            <div class="meter-head"><span class="meter-value">{{ $pct }}%</span><span class="muted">used of {{ $money($budget['envelope']) }}</span></div>
            <div @class(['meter', 'meter-warn' => $budget['reduced'], 'meter-bad' => !$budget['available']]) role="meter" aria-valuemin="0" aria-valuemax="100" @if($pctValid) aria-valuenow="{{ $pct }}" @endif aria-label="Budget used"><span @if($pctValid) style="--w: {{ $pct }}%" @endif></span></div>
            <dl class="kv kv-grid">
                <dt>Envelope</dt><dd class="num">{{ $money($budget['envelope']) }}</dd>
                <dt>Fixed subscriptions</dt><dd class="num">{{ $money($budget['fixed_costs']) }}</dd>
                <dt>Settled usage</dt><dd class="num">{{ $money($budget['actual']) }}</dd>
                <dt>Pending reservations</dt><dd class="num">{{ $money($budget['reserved']) }}</dd>
                <dt>Remaining</dt><dd class="num strong">{{ $money($budget['remaining']) }}</dd>
            </dl>
            @if($budget['reduced'])<p class="unknown">At or above 80% of the envelope: routine research is reduced to material events.</p>@endif
            @if(!$budget['available'])<p class="unknown">Budget exhausted: the remainder cannot fund a bounded research call, so optional research stops; monitoring continues.</p>@endif
        @endif
    </x-panel>

    <x-panel title="Request research" subtitle="Unchanged evidence and thesis versions reuse cached research instead of calling the model again." icon="spark">
        @if($research['subjects'])
        <form method="post" action="{{ route('mcp.research.request') }}" class="stack">@csrf<input type="hidden" name="request_id" value="{{ (string) Illuminate\Support\Str::uuid() }}">
            <label class="field">Symbol<select name="symbol">@foreach($research['subjects'] as $subject)<option>{{ $subject }}</option>@endforeach</select></label>
            <label class="field">Thesis<select name="thesis_key"><option value="">Most recently revised open thesis, if any</option>@foreach($theses as $key => $thesis)@unless(App\Services\Research\ResearchEvents::closed($thesis))<option value="{{ $key }}">{{ $thesis->payload['symbol'] }} · {{ $thesis->payload['sleeve'] }} · v{{ $thesis->id }}</option>@endunless @endforeach</select></label>
            <div class="form-actions"><button class="btn btn-primary" type="submit"><x-icon name="spark" :size="16" />Request research</button></div>
        </form>
        @else
            <p class="muted">No held, watched or thesis symbols are available.</p>
        @endif
    </x-panel>
</div>

<div class="section-head"><div><h2>Latest validated research</h2><p>Schema-validated model output linked to supplied evidence.</p></div></div>
@forelse($research['latest'] as $item)
    @php $proposal = $item->result['proposal']; @endphp
    <article class="card research-card">
        <header class="card-head">
            <div class="instrument"><span class="ticker" aria-hidden="true">{{ substr($item->subject, 0, 1) }}</span><div><strong>{{ $item->subject }}</strong><small>Non-executable research proposal · generated <x-time :at="$item->finished_at" /> by {{ $item->model }} ({{ $item->prompt_version }}) · cache expires <x-time :at="$item->expires_at" /></small></div></div>
            <div class="pill-row"><x-pill :value="$item->result['thesis_assessment']">assessment {{ $item->result['thesis_assessment'] }}</x-pill><x-pill tone="info">stance {{ $proposal['stance'] }}</x-pill></div>
        </header>
        <p class="lead">{{ $item->result['summary'] }}</p>
        <h4 class="mini-head">Material facts</h4>
        <ul class="facts">
        @foreach($item->result['material_facts'] as $fact)
            <li><p>{{ $fact['statement'] }}</p><small class="muted">Model-stated as of {{ $fact['as_of'] }}</small>
            @foreach($fact['source_record_ids'] as $sourceId)
                @php $cited = $research['cited'][$sourceId] ?? null; @endphp
                <div class="evidence"><x-icon name="database" :size="13" /><span>Evidence: {{ $cited ? ($cited->kind === 'quote' ? 'Quote · '.$cited->provider.' · last '.($cited->payload['data']['last'] ?? 'unknown') : $cited->payload['label']) : 'missing source' }}</span>
                    <span class="muted">source time <x-time :at="$cited?->source_as_of" fallback="unknown" /></span><span class="muted">retrieved <x-time :at="$cited?->retrieved_at" fallback="unknown" /></span>
                    @if($cited?->kind === 'quote')@php $quoteState = App\Services\Monitoring\RiskObservations::freshness($cited->source_as_of->toIso8601String(), $cited->retrieved_at->toIso8601String()); @endphp<x-pill :value="$quoteState" />@endif
                </div>
            @endforeach
            </li>
        @endforeach
        </ul>
        <dl class="kv kv-grid">
            <dt>Uncertainties</dt><dd>{{ implode('; ', $item->result['uncertainties']) ?: 'None stated' }}</dd>
            <dt>Catalyst</dt><dd>{{ $proposal['catalyst'] ?? 'None stated' }}</dd>
            <dt>Horizon</dt><dd>{{ $proposal['horizon'] }}</dd>
            <dt>Review by</dt><dd><x-time :at="$proposal['review_at']" format="M j, Y H:i T" /></dd>
            <dt>Invalidation</dt><dd><code>{{ $proposal['invalidation']['type'] }}</code> {{ $proposal['invalidation']['operator'] }} {{ $proposal['invalidation']['threshold'] }} ({{ $proposal['invalidation']['description'] }})</dd>
            <dt>Risks</dt><dd>{{ implode('; ', $proposal['risks']) ?: 'None stated' }}</dd>
        </dl>
        <footer class="card-foot card-actions">
            <span class="muted">The operator, not the model, decides whether this becomes a thesis version.</span>
            <form method="post" action="{{ route('mcp.research.adopt', $item->id) }}">@csrf<button class="btn btn-secondary btn-sm" type="submit"><x-icon name="check" :size="15" />Adopt as new thesis version</button></form>
        </footer>
    </article>
@empty
    <div class="empty"><x-icon name="spark" :size="22" /><p>No validated research recorded. Nothing has been inferred.</p></div>
@endforelse

<div class="grid-2">
    <x-panel title="Local research watchlist" subtitle="Local only; the brokerage watchlist is never edited." icon="research">
        <ul class="watchlist">
        @forelse($research['watchlist'] as $watch)
            <li><div><strong>{{ $watch->symbol }}</strong>@if($watch->note)<small class="muted">{{ $watch->note }}</small>@endif</div>
                <form method="post" action="{{ route('mcp.research.unwatch', $watch->id) }}">@csrf<button class="btn btn-ghost btn-sm" type="submit" aria-label="Remove {{ $watch->symbol }}"><x-icon name="x" :size="14" />Remove</button></form></li>
        @empty
            <li class="muted">Empty.</li>
        @endforelse
        </ul>
        <form method="post" action="{{ route('mcp.research.watch') }}" class="inline-fields">@csrf
            <label class="field">Symbol<input name="symbol" maxlength="16" required autocomplete="off" spellcheck="false"></label>
            <label class="field grow">Note<input name="note" maxlength="500"></label>
            <button class="btn btn-secondary" type="submit"><x-icon name="plus" :size="16" />Add to local watchlist</button>
        </form>
    </x-panel>

@if($research['subjects'])
<details class="panel disclosure">
    <summary>
        <div class="panel-title"><span class="panel-icon"><x-icon name="plus" :size="16" /></span><div><h3>Record evidence</h3><p>Excerpts are stored as untrusted data. Instructions inside them are never followed and cannot change policy.</p></div></div>
        <x-icon name="chevron" class="disclosure-chevron" />
    </summary>
    <div class="panel-body">
    <form method="post" action="{{ route('mcp.research.evidence') }}" class="form-grid">@csrf
        <label class="field">Symbol<select name="symbol">@foreach($research['subjects'] as $subject)<option>{{ $subject }}</option>@endforeach</select></label>
        <label class="field">Kind<select name="kind"><option>article</option><option>filing</option><option>transcript</option><option>note</option></select></label>
        <label class="field span-2">Title<input name="label" maxlength="160" required></label>
        <label class="field span-2">HTTPS URL<input name="url" maxlength="500" inputmode="url" placeholder="https://"></label>
        <label class="field span-2">Published (America/Chicago)<input type="datetime-local" name="published_at" required></label>
        <label class="field span-full">Excerpt<textarea name="excerpt" rows="4" minlength="20" maxlength="{{ config('research.max_excerpt_chars') }}" required></textarea></label>
        <div class="form-actions span-full"><button class="btn btn-primary" type="submit"><x-icon name="check" :size="16" />Record evidence</button></div>
    </form>
    </div>
</details>
@endif
</div>

<x-panel title="Market and news coverage · {{ $research['coverage']['provider'] }}" icon="database" flush>
        <div class="table-wrap"><table class="data">
            <thead><tr><th>Field</th><th>Supported</th><th>Timestamp quality</th><th>Scope</th></tr></thead>
            <tbody>
            @foreach($research['coverage']['coverage'] as $field => $coverage)
                <tr><td><code>{{ $field }}</code></td><td><x-pill :tone="$coverage['supported'] ? 'ok' : 'warn'">{{ $coverage['supported'] ? 'Yes' : 'No (unknown)' }}</x-pill></td><td>{{ $coverage['timestamp_quality'] }}</td><td>{{ $coverage['scope'] }}</td></tr>
            @endforeach
            <tr><td><code>operator_evidence</code></td><td><x-pill tone="info">Yes (untrusted)</x-pill></td><td>{{ $research['coverage']['operator_evidence']['timestamp_quality'] }}</td><td>{{ $research['coverage']['operator_evidence']['scope'] }}</td></tr>
            </tbody>
        </table></div>
</x-panel>

<x-panel title="Research events" subtitle="Deduplicated material events and operator requests" icon="bell" flush>
    <div class="table-wrap"><table class="data">
        <thead><tr><th>Subject</th><th>Kind / Priority</th><th>Status</th><th>Seen</th></tr></thead>
        <tbody>
        @forelse($research['events'] as $event)
            <tr><td><strong>{{ $event->subject }}</strong></td><td>{{ $event->kind }} / {{ $event->priority }}</td>
                <td><x-pill :value="$event->status" />@if($event->status_reason)<small class="unknown block">{{ $event->status_reason }}</small>@endif</td>
                <td>{{ $event->occurrences }}× · first <x-time :at="$event->first_seen_at" /><small class="muted block">last <x-time :at="$event->last_seen_at" /></small></td></tr>
        @empty
            <tr><td colspan="4" class="empty-cell">No research events.</td></tr>
        @endforelse
        </tbody>
    </table></div>
</x-panel>

<x-panel title="Research runs" subtitle="Every model call, failure and cost reservation" icon="terminal" flush>
    <div class="table-wrap"><table class="data">
        <thead><tr><th>Run UUID / Subject</th><th>Status</th><th>Model / Prompt</th><th>Attempts / Tokens</th><th class="num">Cost</th></tr></thead>
        <tbody>
        @forelse($research['runs'] as $item)
            <tr><td><code class="id">{{ $item->id }}</code><small class="block">{{ $item->subject }}</small></td>
                <td><x-pill :value="$item->status" />@if($item->error_code)<small class="block"><code>{{ $item->error_code }}</code></small>@endif</td>
                <td>{{ $item->model }}<small class="muted block">{{ $item->prompt_version }}</small></td>
                <td class="mono">{{ $item->attempts }} / {{ $item->input_tokens ?? '?' }} in, {{ $item->output_tokens ?? '?' }} out</td>
                <td class="num">{{ $money($item->actual_cost) }}<small class="muted block">reserved {{ $money($item->reserved_cost) }} · {{ $item->cost_basis ?? 'no call' }}</small></td></tr>
        @empty
            <tr><td colspan="5" class="empty-cell">No research runs.</td></tr>
        @endforelse
        </tbody>
    </table></div>
</x-panel>
@endif
