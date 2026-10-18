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

<x-callout tone="warn" :title="$paper ? 'Paper policy selected; automatic execution is unavailable' : 'Paper settings are drafts only'">Saving or restoring creates a draft. Applying a saved draft selects rules for a separate virtual portfolio and initializes virtual cash once. Neither action starts a scheduler, an AI request or a trading engine. Effective research still uses application configuration and the reviewed prompt below. There is no live-mode switch.</x-callout>

<div class="grid-2">
    <x-panel title="Currently used by this application" icon="shield">
        <dl class="kv kv-grid">
            <dt>Research</dt><dd>{{ config('research.enabled') ? 'Enabled' : 'Disabled' }} · {{ $research ? ($research['readiness'] ?? 'Ready') : 'Account unbound' }}</dd>
            <dt>Configured model</dt><dd>{{ config('research.model') ?: 'Not configured' }}</dd>
            <dt>Research dispatch</dt><dd>Every five minutes when research is enabled; this detects pending events, not a guaranteed model call every five minutes.</dd>
            <dt>Research cache</dt><dd>{{ config('research.cache_hours') }} hours on material thesis/evidence inputs; separate M5 decision cadence is not implemented.</dd>
            <dt>Prompt version</dt><dd>{{ App\Services\Research\ResearchPrompt::VERSION }}</dd>
            <dt>Scope</dt><dd>Read-only research. Model output cannot change limits or submit orders.</dd>
        </dl>
        <p class="note">Configuration shown belongs to this running checkout. Production and development settings can differ.</p>
    </x-panel>
    <x-panel title="How rules reach the AI" icon="spark">
        <p>The planned M5 decision runner will attach the applicable saved-and-activated paper policy and guidance version to each bounded request. Laravel will enforce numeric limits independently.</p>
        <p>Today, saved paper drafts are <strong>not sent</strong>. ChatGPT conversations do not automatically update this application. You can paste a settings proposal below, review the editor and save a new draft yourself.</p>
        <p class="note">Strategy guidance can describe what to look for. It cannot grant brokerage access, change credentials or bypass enforced limits. Changing an allocation target does not rebalance any holdings.</p>
    </x-panel>
</div>

@if($errors->any())
    <x-callout tone="bad" title="Change not recorded"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></x-callout>
@endif

@if(!$connection?->bound_account_id)
    <x-callout tone="info">Bind an account before saving account-scoped paper planning versions. No defaults have been saved.</x-callout>
@else
<x-panel title="Applied paper plan and virtual cash" subtitle="Simulation only. These balances are not brokerage funds. There is no simulated order/fill engine yet." icon="wallet">
    @if($paper)
        <dl class="kv kv-grid">
            <dt>Applied rules</dt><dd>Draft v{{ $paper['application']->snapshot['mandate_version'] }} · paper policy revision {{ $paper['application']->revision }} · <x-time :at="$paper['application']->applied_at" /></dd>
            <dt>Virtual starting cash</dt><dd><x-money :value="$paper['portfolio']->starting_cash" /></dd>
            <dt>Virtual cash balance</dt><dd><x-money :value="$paper['portfolio']->cash" /></dd>
            <dt>Reserved virtual cash</dt><dd><x-money :value="$paper['portfolio']->reserved_cash" /></dd>
            <dt>Available virtual cash</dt><dd><x-money :value="$paper['available_cash']" /></dd>
            <dt>Cash ledger version</dt><dd>{{ $paper['portfolio']->ledger_version }}</dd>
            <dt>AI / automatic cadence</dt><dd>Not connected to this paper plan. No paper AI review or execution has been started.</dd>
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
