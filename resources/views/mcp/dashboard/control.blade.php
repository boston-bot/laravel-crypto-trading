<x-panel title="Forward paper dashboard" subtitle="Virtual portfolio, executions, recorded evaluations and data readiness." icon="wallet">
    <a class="btn btn-secondary" href="{{ route('mcp.paper') }}">Open Paper Trading</a>
</x-panel>

@php
    $latest = $control['latest'];
    $saved = $latest?->payload ?? [];
    $defaults = ['scan_seconds' => 60, 'decision_minutes' => 5, 'news_minutes' => 5, 'monthly_budget' => '100.00'];
    $fields = [
        'initial_cash' => 'Virtual starting cash (USD)', 'long_term_pct' => 'Long-term allocation (%)',
        'active_pct' => 'Active allocation (%)', 'cash_pct' => 'Cash allocation (%)',
        'max_position_pct' => 'Maximum position (%)', 'daily_loss_pause_pct' => 'Daily paper loss pause (%)',
        'scan_seconds' => 'Local scan interval (seconds)', 'decision_minutes' => 'AI decision interval (minutes)',
        'news_minutes' => 'News polling interval (minutes)', 'monthly_budget' => 'Monthly AI + data envelope (USD)',
        'ai_guidance' => 'AI investment guidance',
    ];
    $base = old('base_version', $latest?->version ?? 0);
    $paper = $control['paper'];
    $pretty = fn ($value) => json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
@endphp

<x-callout tone="warn" :title="$paper ? 'Paper policy selected; automatic execution is unavailable' : 'Paper settings are drafts only'">Saving or restoring creates a draft. Applying it selects rules for a separate virtual portfolio and initializes virtual cash once. Neither action enables AI requests or scheduling. Paper proposals require separate handoff permission, applied execution rules and complete inputs before an order can be created. Real-provider fill coverage remains incomplete. There is no live-mode switch.</x-callout>

<div class="grid-2">
    <x-panel title="Currently used by this application" icon="shield">
        <dl class="kv kv-grid">
            <dt>Research</dt><dd>{{ config('research.enabled') ? 'Enabled' : 'Disabled' }} · {{ $research ? ($research['readiness'] ?? 'Ready') : 'Account unbound' }}</dd>
            <dt>Configured model</dt><dd>{{ config('research.model') ?: 'Not configured' }}</dd>
            <dt>Research dispatch</dt><dd>Every five minutes when research is enabled; this detects pending events, not a guaranteed model call every five minutes.</dd>
            <dt>Research cache</dt><dd>{{ config('research.cache_hours') }} hours on material thesis/evidence inputs. Paper decisions use separate short-lived windows, not this cache.</dd>
            <dt>Prompt version</dt><dd>{{ App\Services\Research\ResearchPrompt::VERSION }}</dd>
            <dt>Scope</dt><dd>Read-only research. Model output cannot change limits or submit orders.</dd>
        </dl>
        <p class="note">Configuration shown belongs to this running checkout. Production and development settings can differ.</p>
    </x-panel>
    <x-panel title="How strategy signals are evaluated" icon="spark">
        <p>ChatGPT maintains dedicated watchlists outside this application. The app reads their symbols, checks eligibility, assigns a daily playbook and evaluates entry and exit rules from stored market data.</p>
        <p>Signals are paper evaluation records. Risk sizing, dated costs and paper handoff require separately applied settings and activation. The superseded AI decision runner stays disabled.</p>
        <p class="note">Watchlist membership nominates a symbol; it cannot grant permissions, set prices or change risk limits.</p>
    </x-panel>
</div>

<x-panel title="Historical strategy experiments" subtitle="Queue a run, compare variants and inspect simulated entries, exits and open positions." icon="performance">
    <a class="btn btn-secondary" href="{{ route('mcp.experiments.index') }}">Open experiments</a>
</x-panel>

@if($errors->any())
    <x-callout tone="bad" title="Change not recorded"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></x-callout>
@endif

@if(!$connection?->bound_account_id)
    <x-callout tone="info">Bind an account before saving account-scoped paper planning versions. No defaults have been saved.</x-callout>
@else
<x-panel title="Strategy signal history" subtitle="Paper evaluation only. These records do not place orders." icon="shield">
    <p>Signal evaluation {{ config('paper.signals_enabled') ? 'enabled · every five minutes' : 'disabled' }}.</p>
    @forelse($control['signals'] as $signal)
        <details><summary>{{ $signal->symbol }} · {{ $signal->side ?? $signal->status }} · {{ $signal->reason }}</summary>
            <p>Cutoff <x-time :at="$signal->cutoff" /> · rule {{ $signal->rule_version }} · parameters {{ $signal->parameters_version }}</p>
            <pre class="code-block">{{ $pretty($signal->payload) }}</pre>
        </details>
    @empty
        <p class="note">No strategy evaluations recorded for this paper portfolio.</p>
    @endforelse
    {{ $control['signals']->links('mcp.pagination') }}
</x-panel>
<x-panel title="Deterministic eligibility rules" subtitle="Membership nominates symbols; stored broker and regulatory facts must pass every rule. Unknown data blocks entries." icon="shield">
    @php
        $eligibilityPolicy = $control['eligibility']['policy'];
        $eligibilityParameters = $eligibilityPolicy?->payload['parameters'] ?? App\Services\PaperTrading\RuleEligibilityPolicies::DEFAULTS;
    @endphp
    <p>Rule evaluation {{ config('paper.rule_eligibility_enabled') ? 'enabled' : 'disabled' }} · {{ $eligibilityPolicy ? 'Watchlist rule universe selected · policy v'.$eligibilityPolicy->version : 'Current execution allowlist still selected' }}.</p>
    <p class="note">Listing, 252-session history and 20-session dollar volume require complete stored market-reference reads. Missing or stale evidence blocks eligibility. Extra broker fields and model text cannot supply these facts. Applying rules blocks the superseded model handoff, preserves holdings and requires current watchlist membership for entries. Exits stay independent.</p>
    @if($paper && $control['execution']['applied'])
    <form method="post" action="{{ route('mcp.control.eligibility.apply') }}" class="stack">@csrf
        <input type="hidden" name="base_version" value="{{ $eligibilityPolicy->version ?? 0 }}">
        <input type="hidden" name="account_scope" value="{{ App\Services\Monitoring\AccountScope::key($connection) }}">
        <input type="hidden" name="request_id" value="{{ (string) Illuminate\Support\Str::uuid() }}">
        @foreach(['minimum_price' => 'Minimum bid price (USD)', 'minimum_dollar_volume' => 'Minimum 20-session average dollar volume (USD)', 'maximum_spread_pct' => 'Maximum spread (% of midpoint)'] as $field => $label)
            <label class="field">{{ $label }}<input name="parameters[{{ $field }}]" value="{{ $eligibilityParameters[$field] }}" required inputmode="decimal" maxlength="24"></label>
        @endforeach
        <label class="check"><input type="checkbox" name="confirm_rules" value="1" required><span>Replace the fixed allowlist with these versioned watchlist eligibility rules. Missing history blocks new entries. Do not enable any service or change cash, costs or risk limits.</span></label>
        <button class="btn btn-secondary" type="submit">Apply eligibility rules</button>
    </form>
    @endif
    @if($eligibilityPolicy)
    <form method="post" action="{{ route('mcp.control.eligibility.assess') }}" class="stack">@csrf
        <input type="hidden" name="account_scope" value="{{ App\Services\Monitoring\AccountScope::key($connection) }}">
        <input type="hidden" name="request_id" value="{{ (string) Illuminate\Support\Str::uuid() }}">
        <input type="hidden" name="cutoff" value="{{ Carbon\CarbonImmutable::now()->toISOString() }}">
        <button class="btn btn-secondary" type="submit">Record eligibility from stored data</button>
    </form>
    @endif
    @foreach($control['eligibility']['assessments'] as $assessment)
        <details><summary>{{ $assessment->symbol }} · {{ $assessment->status }} · rule {{ $assessment->rule_version }} · policy #{{ $assessment->policy_id }}</summary>
            <p>Cutoff <x-time :at="$assessment->cutoff" /> · recorded <x-time :at="$assessment->recorded_at" /></p>
            <pre class="code-block">{{ $pretty($assessment->payload) }}</pre>
        </details>
    @endforeach
    {{ $control['eligibility']['assessments']->links('mcp.pagination') }}
</x-panel>
<x-panel title="External orders and entry pause" subtitle="Review each observed external order. Acknowledging an alert does not clear this pause." icon="shield">
    <p>Detection {{ config('mcp.external_order_detection_enabled') ? 'enabled' : 'disabled' }}. {{ $control['external_orders']['pending']->total() }} orders awaiting review.</p>
    @if($control['external_orders']['entry_pause_reason'])
        <x-callout tone="warn" title="New entries paused">{{ $control['external_orders']['entry_pause_reason'] }}. Position exits remain independent.</x-callout>
    @elseif(!config('mcp.external_order_detection_enabled'))
        <p class="note">Order detection is disabled; this is not evidence of current broker-order coverage.</p>
    @else
        <p>No external-order pause. Every other eligibility, data and execution check still applies.</p>
    @endif
    @foreach($control['external_orders']['pending'] as $external)
        @php $orderEvidence = $external->payload['order']; @endphp
        <div class="stack">
            <p><strong>{{ $orderEvidence['symbol'] ?? 'Symbol unavailable' }}</strong> · {{ $orderEvidence['side'] ?? 'Side unavailable' }} · first observed status: {{ $orderEvidence['status'] ?? 'unknown' }}<br>
                Broker order <code>{{ $orderEvidence['id'] }}</code> · observed <x-time :at="$external->retrieved_at" /></p>
            <form method="post" action="{{ route('mcp.control.external-order.review', $external->id) }}" class="stack">@csrf
                <input type="hidden" name="account_scope" value="{{ App\Services\Monitoring\AccountScope::key($connection) }}">
                <input type="hidden" name="request_id" value="{{ (string) Illuminate\Support\Str::uuid() }}">
                <label class="field">Review note<input name="note" required maxlength="500"></label>
                <label class="check"><input type="checkbox" name="confirm_review" value="1" required><span>I reviewed this external order and its account impact. Record my review of this order.</span></label>
                <button class="btn btn-secondary" type="submit">Record order review</button>
            </form>
        </div>
    @endforeach
    {{ $control['external_orders']['pending']->links() }}
    <p class="note">This application has no agentic live-submission path yet, so all observed broker orders require review, including completed or cancelled orders. Reviewing an order does not cancel it or authorize a future trade. The latest complete equity-order read must be less than five minutes old when detection is enabled.</p>
</x-panel>
<x-panel title="Dedicated strategy watchlists" subtitle="ChatGPT supplies symbols. This application checks eligibility and makes deterministic paper decisions." icon="shield">
    <p>Intake {{ config('paper.watchlist_intake_enabled') ? 'enabled' : 'disabled' }} · every five minutes when enabled. Membership only nominates symbols; execution eligibility remains unverified. The current execution allowlist remains in force.</p>
    <p>{{ ($watchlist['fresh'] ?? false) ? 'Fresh membership available' : 'No fresh membership: new strategy entries blocked' }} · {{ count($watchlist['symbols'] ?? []) }} nominated symbols · {{ count($watchlist['data_symbols'] ?? []) }} data-only symbols.</p>
    @if($watchlist['revision'] ?? null)
        <p>Revision {{ $watchlist['revision']->id }} · retrieved <x-time :at="$watchlist['revision']->retrieved_at" />. Broker source time is unavailable.</p>
        <details><summary>Membership and skipped items</summary><pre class="code-block">{{ $pretty($watchlist['revision']->payload['members']) }}</pre></details>
    @endif
    <form method="post" action="{{ route('mcp.control.watchlist') }}" class="stack">@csrf
        <input type="hidden" name="base_version" value="{{ $watchlist['setting']->version ?? 0 }}">
        <input type="hidden" name="account_scope" value="{{ App\Services\Monitoring\AccountScope::key($connection) }}">
        <input type="hidden" name="request_id" value="{{ (string) Illuminate\Support\Str::uuid() }}">
        @php($configured = ($watchlist['setting'] ?? null) ? App\Services\PaperTrading\StrategyWatchlists::lists($watchlist['setting']) : [])
        @for($i = 0; $i < 5; $i++)
            <div class="inline-fields">
                <label class="field">Robinhood list name<input name="lists[{{ $i }}][name]" maxlength="128" value="{{ $configured[$i]['name'] ?? '' }}"></label>
                <label class="field">Role<select name="lists[{{ $i }}][role]">
                    <option value="nominate" @selected(($configured[$i]['role'] ?? 'nominate') === 'nominate')>Nominate for evaluation</option>
                    <option value="data_only" @selected(($configured[$i]['role'] ?? null) === 'data_only')>Data only (warm-up)</option>
                </select></label>
            </div>
        @endfor
        <label class="check"><input type="checkbox" name="confirm_dedicated" value="1" required><span>These lists are dedicated to this strategy and separate from my personal watchlists.</span></label>
        <button class="btn btn-secondary" type="submit">Save strategy watchlists</button>
    </form>
    <p class="note">Nominating lists put symbols up for deterministic evaluation; data-only lists only collect history and profiles in advance. More than 75 items across nominating lists, or across data-only lists, rejects the entire read; so does a failed read of any list. Failed reads retain the last valid membership for less than 30 minutes. Removed symbols receive no new entries; position exits and risk checks operate independently. Saving a list requires a fresh read and does not enable intake.</p>
</x-panel>
<x-panel title="Applied paper plan and virtual cash" subtitle="Simulation only. These balances are not brokerage funds. Offline synthetic fills are separate from AI proposals and broker activity." icon="wallet">
    @if($paper)
        <dl class="kv kv-grid">
            <dt>Applied rules</dt><dd>Draft v{{ $paper['application']->snapshot['mandate_version'] }} · paper policy revision {{ $paper['application']->revision }} · <x-time :at="$paper['application']->applied_at" /></dd>
            <dt>Virtual starting cash</dt><dd><x-money :value="$paper['portfolio']->starting_cash" /></dd>
            <dt>Virtual cash balance</dt><dd><x-money :value="$paper['portfolio']->cash" /></dd>
            <dt>Reserved virtual cash</dt><dd><x-money :value="$paper['portfolio']->reserved_cash" /></dd>
            <dt>Available virtual cash</dt><dd><x-money :value="$paper['available_cash']" /></dd>
            <dt>Cash ledger version</dt><dd>{{ $paper['portfolio']->ledger_version }}</dd>
            <dt>AI / automatic cadence</dt><dd>Separate opt-in: {{ config('paper.decisions_enabled') ? 'enabled in configuration' : 'disabled in configuration' }}. See recorded decision windows below; automatic order execution is unavailable.</dd>
        </dl>
        <details><summary>Inspect the applied paper rules and guidance</summary><pre class="code-block">{{ $pretty($paper['application']->snapshot) }}</pre></details>
        <details><summary>Inspect virtual cash entries</summary><div class="table-wrap"><table class="data"><thead><tr><th>Ledger version</th><th>Entry</th><th>Amount</th><th>Balance</th><th>Recorded</th></tr></thead><tbody>
            @foreach($paper['cash_entries'] as $entry)<tr><td>{{ $entry->ledger_version }}</td><td>{{ $entry->kind }}</td><td><x-money :value="$entry->amount" /></td><td><x-money :value="$entry->balance_after" /></td><td><x-time :at="$entry->recorded_at" /></td></tr>@endforeach
        </tbody></table></div></details>
    @else
        <p>No virtual portfolio or applied paper policy exists for this account binding. No cash has been inferred from brokerage balances.</p>
    @endif
    @if($latest && (!$paper || $paper['application']->mandate_version_id !== $latest->id))
        <p>Apply the latest <strong>saved draft v{{ $latest->version }}</strong> below. This does not apply unsaved editor or proposal changes. Complete every capital, allocation, risk, budget and interval field first. Starting cash becomes fixed once the virtual portfolio exists; a later policy change cannot reset its cash history.</p>
        <form method="post" action="{{ route('mcp.control.apply', $latest->id) }}" class="stack">@csrf
            <input type="hidden" name="base_version" value="{{ $latest->version }}"><input type="hidden" name="base_policy_revision" value="{{ $paper['application']->revision ?? 0 }}">
            <input type="hidden" name="account_scope" value="{{ App\Services\Monitoring\AccountScope::key($connection) }}"><input type="hidden" name="request_id" value="{{ (string) Illuminate\Support\Str::uuid() }}">
            <label class="check"><input type="checkbox" name="confirm_paper" value="1" required><span>I have reviewed the saved draft. Apply it only to the virtual portfolio; do not start automatic research or trading.</span></label>
            <button class="btn btn-secondary" type="submit">Apply saved draft to paper portfolio</button>
        </form>
    @elseif(!$latest)
        <p class="note">Save a complete paper draft before applying it.</p>
    @else
        <p class="note">The latest saved draft is already applied. Editing or restoring saves another draft; it does not silently replace these applied rules.</p>
    @endif
</x-panel>

<x-panel title="Edit paper mandate draft" subtitle="Blank means unspecified. Saving does not apply a draft; use the separate paper-only action after review." icon="shield">
    <p>Multi-day to multi-week positions · broad US stock/ETF discovery · no options, shorts, borrowing, leveraged/inverse products or crypto. Exact eligible universe and simulation rules remain M5 work.</p>
    <form method="post" action="{{ route('mcp.control.store') }}" class="stack">@csrf
        <input type="hidden" name="base_version" value="{{ $base }}">
        <input type="hidden" name="account_scope" value="{{ old('account_scope', App\Services\Monitoring\AccountScope::key($connection)) }}">
        <input type="hidden" name="request_id" value="{{ old('request_id', (string) Illuminate\Support\Str::uuid()) }}">
        <div class="form-grid">
        @foreach($fields as $name => $label)
            @if($name !== 'ai_guidance')
            <label class="field">{{ $label }}
                <input name="{{ $name }}" value="{{ old($name, array_key_exists($name, $saved) ? $saved[$name] : ($defaults[$name] ?? '')) }}" inputmode="{{ in_array($name, ['scan_seconds', 'decision_minutes', 'news_minutes']) ? 'numeric' : 'decimal' }}" maxlength="32" autocomplete="off">
            </label>
            @endif
        @endforeach
        </div>
        <p class="note">Allocations must all be blank or total exactly 100%. A position limit is a percentage of total paper equity; the planned daily loss pause uses verified start-of-session paper equity. These fields do not configure the current risk monitor. The monthly envelope includes AI and data and cannot exceed $150.</p>
        <label class="field">AI investment guidance (draft)<textarea name="ai_guidance" rows="6" maxlength="6000" placeholder="Describe evidence to consider, investment preferences and when to prefer WAIT.">{{ old('ai_guidance', $saved['ai_guidance'] ?? '') }}</textarea></label>
        <label class="field">Reason for this change<input name="reason" value="{{ old('reason') }}" required maxlength="500" placeholder="What changed and why?"></label>
        <div class="form-actions"><button class="btn btn-primary" type="submit">Save new paper draft</button><span class="muted">Nothing is activated or submitted.</span></div>
    </form>
</x-panel>

<x-panel title="Saved draft and proposal exchange" subtitle="Saved values below are separate from the editor, including when a pasted proposal is being reviewed." icon="database">
    <p>{{ $latest ? 'Latest saved draft: version '.$latest->version : 'No saved draft yet.' }}</p>
    <details><summary>View / copy saved planning JSON</summary><pre class="code-block">{{ $pretty($latest?->payload ?? array_replace(array_fill_keys(App\Services\PaperTrading\PaperMandates::FIELDS, null), $defaults)) }}</pre></details>
    <p class="note">Before the first save, the JSON contains proposed defaults, not saved settings. You can share these planning fields with ChatGPT, request changes and paste the returned JSON object here. Do not include credentials or account identifiers. Omitted fields retain the latest saved value.</p>
    <form method="post" action="{{ route('mcp.control.proposal') }}" class="stack">@csrf
        <input type="hidden" name="base_version" value="{{ $latest?->version ?? 0 }}">
        <input type="hidden" name="account_scope" value="{{ App\Services\Monitoring\AccountScope::key($connection) }}">
        <label class="field">Proposed settings JSON<textarea name="proposal_json" rows="5" maxlength="16000" required placeholder='{"decision_minutes": 10}'></textarea></label>
        <button class="btn btn-secondary" type="submit">Preview proposal in editor</button>
    </form>
</x-panel>

<x-panel title="Draft version history" subtitle="Restoring copies old settings into a new draft. It does not delete history, undo fills or activate trading." icon="clock" flush>
    <div class="table-wrap"><table class="data"><thead><tr><th>Version / saved</th><th>Change / author</th><th>Settings</th><th>Restore</th></tr></thead><tbody>
    @forelse($control['history'] as $item)
        <tr><td>v{{ $item->version }}<small class="block"><x-time :at="$item->created_at" /></small></td><td>{{ $item->reason }}<small class="block">Operator #{{ $item->actor_id }}@if($item->restored_from_id) · restored record #{{ $item->restored_from_id }}@endif</small></td>
            <td><details><summary>View saved fields</summary><pre class="code-block">{{ $pretty($item->payload) }}</pre></details></td>
            <td>@if($item->id !== $latest?->id)<form method="post" action="{{ route('mcp.control.restore', $item->id) }}">@csrf<input type="hidden" name="base_version" value="{{ $latest?->version ?? 0 }}"><input type="hidden" name="account_scope" value="{{ App\Services\Monitoring\AccountScope::key($connection) }}"><input type="hidden" name="request_id" value="{{ (string) Illuminate\Support\Str::uuid() }}"><button class="btn btn-secondary btn-sm" type="submit">Restore as new draft</button></form>@else<span class="muted">Latest draft</span>@endif</td></tr>
    @empty
        <tr><td colspan="4" class="empty-cell">No planning versions recorded.</td></tr>
    @endforelse
    </tbody></table></div>
    {{ $control['history']->links('mcp.pagination') }}
</x-panel>
@endif

@if($control['decisions'])
    @include('mcp.dashboard.paper-execution', ['execution' => $control['execution']])
    @include('mcp.dashboard.paper-decisions', ['decisions' => $control['decisions']])
@endif
@if($control['simulation'])
    @include('mcp.dashboard.paper-simulation', ['simulation' => $control['simulation']])
@endif

<x-panel title="Current research instructions" subtitle="Current code-defined instructions, not a historical request transcript. Paper guidance above is not included." icon="terminal">
    <details><summary>Read the rules currently supplied by the research adapter</summary><pre class="code-block">{{ App\Services\Research\ResearchPrompt::instructions() }}</pre></details>
    <p class="note">Historical runs retain model/prompt versions, hashes, usage and results. Full historical request text is not retained, so this preview must not be presented as exactly what an older run received. M5 must record the applied paper policy/guidance version with each decision.</p>
</x-panel>

@if($research)
<x-panel title="Stored research runs" subtitle="Account-scoped PostgreSQL records. Includes unsuccessful attempts; a run is not necessarily a new model call." icon="research" flush>
    <div class="table-wrap"><table class="data"><thead><tr><th>Subject / run</th><th>Status / model</th><th>Usage / cost</th><th>Stored result</th></tr></thead><tbody>
    @forelse($control['runs'] as $item)
        <tr><td>{{ $item->subject }}<small class="block mono">{{ $item->id }}</small><x-time :at="$item->started_at" /></td>
            <td>{{ $item->status }}<small class="block">{{ $item->model }} · {{ $item->prompt_version }}</small><small>{{ $item->error_code }}</small></td>
            <td>{{ $item->input_tokens ?? 'Unknown' }} in / {{ $item->output_tokens ?? 'Unknown' }} out<small class="block">Recorded USD {{ $item->actual_cost }} · {{ $item->cost_basis ?? 'not settled' }}</small></td>
            <td><details><summary>Result and provenance</summary><p>Prompt hash: <code>{{ $item->prompt_hash }}</code></p><p>Context hash: <code>{{ $item->context_hash ?? 'Unavailable' }}</code></p><pre class="code-block">{{ $pretty($item->result) }}</pre></details></td></tr>
    @empty
        <tr><td colspan="4" class="empty-cell">No research runs recorded for this account binding.</td></tr>
    @endforelse
    </tbody></table></div>{{ $control['runs']->links('mcp.pagination') }}
</x-panel>
<x-panel title="Stored source evidence" subtitle="Paginated PostgreSQL evidence, with source and retrieval times. External text is untrusted data." icon="database" flush>
    <div class="table-wrap"><table class="data"><thead><tr><th>Symbol / source</th><th>Times</th><th>Evidence</th></tr></thead><tbody>
    @forelse($control['sources'] as $item)
        <tr><td>{{ $item->symbol }} · {{ $item->kind }}<small class="block">{{ $item->provider }} · source #{{ $item->id }}</small></td>
            <td>Source <x-time :at="$item->source_as_of" /><small class="block">Retrieved <x-time :at="$item->retrieved_at" /></small></td>
            <td><details><summary>View stored evidence</summary><pre class="code-block">{{ $pretty($item->payload) }}</pre></details></td></tr>
    @empty
        <tr><td colspan="3" class="empty-cell">No source evidence recorded.</td></tr>
    @endforelse
    </tbody></table></div>{{ $control['sources']->links('mcp.pagination') }}
    <p><a href="{{ route('mcp.dashboard', ['page' => 'research']) }}">Research, budget, watchlist and cited findings →</a></p>
</x-panel>
@endif
