<x-panel title="Broker holdings" subtitle="Broker holdings; attribution defaults to unclassified" icon="holdings" flush>
    <div class="table-wrap"><table class="data">
        <thead><tr><th>Instrument</th><th class="num">Quantity</th><th class="num">Value</th><th>Sleeve quantities</th></tr></thead>
        <tbody>
        @forelse($observations['holdings'] ?? [] as $holding)
            @php $symbol = $holding['symbol'] ?? $holding['instrument_key']; @endphp
            <tr>
                <td><div class="instrument"><span class="ticker" aria-hidden="true">{{ substr($symbol, 0, 1) }}</span><div><strong>{{ $symbol }}</strong><small class="mono">{{ $holding['instrument_key'] }}</small></div></div></td>
                <td class="num"><x-quantity :value="$holding['quantity']" /></td>
                <td class="num"><x-money :value="$holding['market_value'] ?? null" /></td>
                <td><div class="chips">@foreach($holding['attribution'] as $sleeve => $quantity)<span class="chip chip-{{ $sleeve }}">{{ $sleeve }}: <b><x-quantity :value="$quantity" /></b></span>@endforeach</div></td>
            </tr>
        @empty
            <tr><td colspan="4" class="empty-cell">{{ $snapshot ? 'Complete equity set is empty.' : 'Holdings unknown.' }}</td></tr>
        @endforelse
        </tbody>
    </table></div>
</x-panel>

<div class="section-head">
    <div><h2>Thesis versions</h2><p>Append-only, sourced theses. The latest version per thesis is shown.</p></div>
</div>
@if($theses->isEmpty())
    <div class="empty"><x-icon name="research" :size="22" /><p>No sourced thesis versions recorded. No historical attribution has been invented.</p></div>
@else
    <div class="card-grid">
    @foreach($theses as $thesis)
        @php
            $t = $thesis->payload;
            $evaluation = collect($observations['theses'] ?? [])->firstWhere('version_id', $thesis->id)['result'] ?? 'not evaluated';
        @endphp
        <article class="card thesis">
            <header class="card-head">
                <div class="instrument"><span class="ticker" aria-hidden="true">{{ substr($t['symbol'], 0, 1) }}</span><div><strong>{{ $t['symbol'] }}</strong><small>{{ $t['sleeve'] }} sleeve · {{ $t['horizon'] ?? 'horizon unknown' }}</small></div></div>
                <x-pill :value="$t['status'] ?? 'active'" />
            </header>
            <p class="thesis-rationale">{{ $t['rationale'] }}</p>
            <dl class="kv kv-compact">
                @if($t['catalyst'] ?? null)<dt>Catalyst</dt><dd>{{ $t['catalyst'] }}</dd>@endif
                <dt>Invalidation</dt><dd><code>{{ $t['invalidation']['type'] }}</code> {{ $t['invalidation']['operator'] ?? '' }} <span class="mono">{{ $t['invalidation']['threshold'] ?? '' }}</span>@if($t['invalidation']['description'] ?? null)<small class="muted block">{{ $t['invalidation']['description'] }}</small>@endif</dd>
                <dt>Latest evaluation</dt><dd><x-pill :value="$evaluation" /></dd>
                <dt>Next review</dt><dd><x-time :at="$t['next_review_at']" format="M j, Y H:i T" /></dd>
            </dl>
            <footer class="card-foot">Version {{ $thesis->id }} · {{ $t['origin'] ?? 'programmatic' }} · {{ count($t['sources']) }} cited sources · recorded <x-time :at="$thesis->effective_at" format="M j, Y H:i T" /></footer>
        </article>
    @endforeach
    </div>
@endif

@if($research && $research['subjects'])
<details class="panel disclosure">
    <summary>
        <div class="panel-title"><span class="panel-icon"><x-icon name="plus" :size="16" /></span><div><h3>Record thesis version</h3><p>Append-only. A new version never edits history and does not create an order or change policy.</p></div></div>
        <x-icon name="chevron" class="disclosure-chevron" />
    </summary>
    <div class="panel-body">
    <form method="post" action="{{ route('mcp.thesis.store') }}" class="form-grid">@csrf
        <label class="field">Revise existing<select name="thesis_key"><option value="">New thesis</option>@foreach($theses as $key => $thesis)<option value="{{ $key }}">{{ $thesis->payload['symbol'] }} · v{{ $thesis->id }}</option>@endforeach</select></label>
        <label class="field">Symbol<select name="symbol">@foreach($research['subjects'] as $subject)<option>{{ $subject }}</option>@endforeach</select></label>
        <label class="field">Sleeve<select name="sleeve"><option>unclassified</option><option>long_term</option><option>active</option></select></label>
        <label class="field">Status<select name="status"><option>active</option><option>watch</option><option>closed</option></select></label>
        <label class="field span-full">Rationale<textarea name="rationale" rows="3" maxlength="2000" required></textarea></label>
        <label class="field span-2">Catalyst<input name="catalyst" maxlength="500"></label>
        <label class="field span-2">Entry conditions<input name="entry_conditions" maxlength="1000"></label>
        <label class="field">Horizon<select name="horizon"><option>weeks</option><option>days</option><option>months</option><option>years</option></select></label>
        <label class="field">Next review (America/Chicago)<input type="datetime-local" name="next_review_at" required></label>
        <label class="field">Invalidation<select name="invalidation_type"><option>manual_review</option><option>fundamental_event</option><option>price</option><option>completed_session_close</option></select></label>
        <label class="field">Operator<select name="invalidation_operator"><option value="">n/a</option><option>lte</option><option>gte</option></select></label>
        <label class="field">Threshold<input name="invalidation_threshold" inputmode="decimal" maxlength="40"></label>
        <label class="field span-3">Description<input name="invalidation_description" maxlength="300"></label>
        @if($research['sources']->isNotEmpty())
        <fieldset class="field span-full checks"><legend>Cited evidence</legend>
            @foreach($research['sources'] as $source)<label class="check"><input type="checkbox" name="sources[]" value="{{ $source->id }}"><span><strong>{{ $source->symbol }}</strong> · {{ $source->payload['label'] }} <small class="muted">(<x-time :at="$source->source_as_of" :relative="false" />)</small></span></label>@endforeach
        </fieldset>
        @endif
        <div class="form-actions span-full"><button class="btn btn-primary" type="submit"><x-icon name="check" :size="16" />Record thesis version</button></div>
    </form>
    </div>
</details>
@endif
