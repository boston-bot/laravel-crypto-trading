@php
    $executionLatest = $execution['latest'];
    $executionPayload = array_replace(array_fill_keys(App\Services\PaperTrading\ExecutionPolicies::FIELDS, null), $executionLatest?->payload ?? []);
    $executionChoices = [
        'decision_sleeve' => ['Paper sleeve for AI proposals', ['active' => 'Active', 'long_term' => 'Long term']],
        'order_type' => ['Paper order type', ['limit' => 'Limit orders']],
        'session' => ['Fill session', ['regular' => 'Regular market hours']],
        'time_in_force' => ['Order duration', ['day' => 'Expire by the session close']],
        'initial_holdings' => ['Starting portfolio', ['cash_only' => 'Virtual cash only; no imported holdings']],
        'allow_entries' => ['Allow paper entries', ['1' => 'Allowed', '0' => 'Disallowed']],
        'allow_exits' => ['Allow paper exits', ['1' => 'Allowed', '0' => 'Disallowed']],
        'allow_ai_sizing' => ['Retired AI target-weight permission (unused by signals)', ['1' => 'Allowed within application limits', '0' => 'Disallowed']],
        'allow_fractional' => ['Fractional shares', ['1' => 'Allowed with verified instrument support', '0' => 'Whole shares only']],
        'allow_fractional_limit' => ['Fractional limit orders', ['1' => 'Allowed with verified instrument support', '0' => 'Disallowed']],
    ];
    $executionNumbers = ['fee_per_share' => 'Simulated fee per share (USD)', 'fee_bps' => 'Additional fee on fill value (basis points; 100 = 1%)', 'slippage_bps' => 'Adverse slippage (basis points)',
        'limit_offset_bps' => 'Limit offset from current ask / bid (basis points)', 'quote_max_age_seconds' => 'Maximum quote age (seconds, 1–300)'];
@endphp
<x-panel title="Signal paper risk and costs" subtitle="Explicit settings for deterministic signals. Applying them does not enable orders or alter balances." icon="shield">
    <p>Signal handoff {{ config('paper.signal_handoff_enabled') ? 'enabled' : 'disabled' }} · applied version {{ $execution['signal_policy']?->version ?? 'none for current settings' }}.</p>
    <p class="note">Whole shares only while fractional limit-order support is unverified. Existing cash floors and sleeve allocations still apply. Costs use the reviewed listed-equity schedule and expire on December 31, 2026; a new review is then required.</p>
    @if($paper && $execution['applied'] && ($control['eligibility']['policy'] ?? null))
    <form method="post" action="{{ route('mcp.paper.signal-execution.apply') }}" class="stack">@csrf
        <input type="hidden" name="account_scope" value="{{ App\Services\Monitoring\AccountScope::key($connection) }}">
        <input type="hidden" name="base_version" value="{{ $execution['signal_policy_latest']?->version ?? 0 }}">
        <input type="hidden" name="execution_version_id" value="{{ $execution['applied']->id }}">
        <input type="hidden" name="request_id" value="{{ Illuminate\Support\Str::uuid() }}">
        @foreach(['risk_per_trade_pct' => 'Planned risk per trade (%) · maximum 1', 'max_position_pct' => 'Maximum position (%) · maximum 20', 'max_open_positions' => 'Maximum open positions · 1–8', 'slippage_bps' => 'Adverse slippage (basis points)', 'limit_offset_bps' => 'Limit offset (basis points)', 'quote_max_age_seconds' => 'Maximum quote age (seconds) · 1–60'] as $field => $label)
            <label class="field">{{ $label }}<input name="signal_execution[{{ $field }}]" required inputmode="decimal" value="{{ old('signal_execution.'.$field, $execution['signal_policy']?->payload[$field] ?? App\Services\PaperTrading\SignalExecutionPolicies::DEFAULTS[$field]) }}"></label>
        @endforeach
        <details><summary>Reviewed cost schedule and sources</summary><pre class="code-block">{{ json_encode(App\Services\PaperTrading\TradingCosts::reviewed(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre></details>
        <label class="check"><input type="checkbox" name="confirm_signal_paper" value="1" required><span>I reviewed these signal paper risk limits and the dated cost schedule. Apply them for the current allocation and execution versions without enabling any service.</span></label>
        <button class="btn btn-secondary" type="submit">Apply signal paper risk and costs</button>
    </form>
    @endif
    @if($execution['signal_policy'])<details><summary>Applied signal settings</summary><pre class="code-block">{{ json_encode($execution['signal_policy']->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre></details>@endif
    @forelse($execution['signal_handoffs'] as $handoff)
        <details><summary>Signal #{{ $handoff->signal_id }} · {{ $handoff->status }} · {{ $handoff->reason }} · paper order {{ $handoff->sim_order_id ?? 'none' }}</summary><pre class="code-block">{{ json_encode($handoff->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre></details>
    @empty
        <p class="note">No signal paper handoffs recorded.</p>
    @endforelse
    {{ $execution['signal_handoffs']->links('mcp.pagination') }}
</x-panel>
<x-panel title="Paper execution settings" subtitle="Owner choices, versioned separately from allocations. No values are inferred from legacy crypto rules." icon="shield">
    @if($control['eligibility']['policy'] ?? null)
        <p class="note">The separate eligibility rule policy replaces this legacy symbol allowlist for entries and data collection. The saved list is retained as execution-version history. The superseded model handoff is blocked; costs, position limits and cash are unchanged.</p>
    @endif
    <p>Applied execution version: {{ $execution['applied']?->version ?? 'None for the current allocation policy' }}. Handoff {{ config('paper.handoff_enabled') ? 'enabled' : 'disabled' }}; market collection {{ config('paper.market_inputs_enabled') ? 'enabled' : 'disabled' }}; synthetic simulator {{ config('paper.offline_simulation_enabled') ? 'enabled' : 'disabled' }}.</p>
    <p class="note">Choose each setting explicitly. The current handoff supports one selected sleeve per version, a symbol allowlist, cash-only initialization and regular-session DAY limit orders. Existing holdings are preserved; applying settings does not reset cash or positions. Real broker inputs currently lack reviewed eligibility, size and corporate-action coverage and cannot produce fills.</p>
    @if($paper)
    <form method="post" action="{{ route('mcp.paper.execution.store') }}">
        @csrf
        <input type="hidden" name="account_scope" value="{{ App\Services\Monitoring\AccountScope::key($connection) }}">
        <input type="hidden" name="base_version" value="{{ $executionLatest?->version ?? 0 }}">
        <input type="hidden" name="request_id" value="{{ Illuminate\Support\Str::uuid() }}">
        <div class="grid-2">
        @foreach($executionChoices as $field => [$label, $choices])
            @php $chosen = old('execution.'.$field, $executionPayload[$field]); $chosen = is_bool($chosen) ? ($chosen ? '1' : '0') : $chosen; @endphp
            <label class="field"><span>{{ $label }}</span><select name="execution[{{ $field }}]"><option value="">Unspecified</option>@foreach($choices as $value => $choice)<option value="{{ $value }}" @selected($chosen !== null && (string) $chosen === (string) $value)>{{ $choice }}</option>@endforeach</select></label>
        @endforeach
        @foreach($executionNumbers as $field => $label)
            <label class="field"><span>{{ $label }}</span><input type="number" name="execution[{{ $field }}]" min="{{ $field === 'quote_max_age_seconds' ? '1' : '0' }}" step="{{ $field === 'quote_max_age_seconds' ? '1' : '0.00000001' }}" value="{{ old('execution.'.$field, $executionPayload[$field]) }}"></label>
        @endforeach
        </div>
        <label class="field"><span>Allowed stock / ETF symbols · comma-separated or one per line</span><textarea name="execution_symbols" rows="3" maxlength="2000">{{ old('execution_symbols', implode(', ', $executionPayload['allowed_symbols'] ?? [])) }}</textarea></label>
        <p class="note">An allowed symbol still needs verified classification and current data. Leave choices unspecified until reviewed. One basis point is 0.01%; the limit offset raises a buy limit above ask and lowers a sell limit below bid. Slippage is a separate adverse fill assumption. Per-share and percentage fees add together on each fill. Choose zero explicitly for any fee component you do not want.</p>
        <label class="field"><span>Reason</span><input name="reason" maxlength="500" required value="{{ old('reason') }}"></label>
        <button class="btn btn-secondary" type="submit">Save execution draft</button>
    </form>
    @if($executionLatest?->kind === 'draft')
    <form method="post" action="{{ route('mcp.paper.execution.apply', ['version' => $executionLatest->id]) }}" class="stack">
        @csrf
        <input type="hidden" name="account_scope" value="{{ App\Services\Monitoring\AccountScope::key($connection) }}"><input type="hidden" name="base_version" value="{{ $executionLatest->version }}"><input type="hidden" name="request_id" value="{{ Illuminate\Support\Str::uuid() }}">
        <label class="check"><input type="checkbox" name="confirm_paper" value="1" required><span>I reviewed this complete execution draft for the current paper allocation policy. Apply it without enabling any service.</span></label>
        <button class="btn btn-secondary" type="submit">Apply execution draft to paper</button>
    </form>
    @endif
    @else
        <p>Apply a complete paper allocation draft before saving account-scoped execution settings.</p>
    @endif
    @if($execution['applied'])<details><summary>Current applied execution rules</summary><pre class="code-block">{{ json_encode($execution['applied']->payload, JSON_PRETTY_PRINT) }}</pre></details>@endif
    @foreach($execution['history'] ?? [] as $version)
        <details><summary>v{{ $version->version }} · {{ $version->kind }} · {{ $version->reason }}</summary><p>Allocation policy #{{ $version->policy_application_id }} · <x-time :at="$version->created_at" /></p><pre class="code-block">{{ json_encode($version->payload, JSON_PRETTY_PRINT) }}</pre></details>
    @endforeach
    {{ $execution['history']?->links('mcp.pagination') }}
</x-panel>
<x-panel title="Execution data readiness" subtitle="Stored observations only. This report makes no network request and does not enable fills." icon="shield">
    <p>{{ $execution['readiness']['ready_count'] }} / {{ $execution['readiness']['symbol_count'] }} symbols have complete real execution data for whole-share paper fills. Each needs fresh broker evidence, stored SEC classification and reconciled corporate actions; fractional-limit coverage remains unavailable.</p>
    @if($execution['readiness']['symbol_count'] === 0)<p>Apply execution settings to inspect the selected symbol universe.</p>@endif
    <details><summary>Inspect every symbol and missing requirement</summary>
        <div class="table-wrap"><table class="data"><thead><tr><th>Symbol / status</th><th>Source</th><th>Coverage checks</th></tr></thead><tbody>
        @foreach($execution['readiness']['symbols'] as $symbol => $readiness)
            <tr><td>{{ $symbol }} · {{ $readiness['status'] }}</td><td>{{ $readiness['input_id'] ? 'Market input #'.$readiness['input_id'] : 'No stored observation' }}</td><td><dl>@foreach($readiness['checks'] as $field => $reason)<dt>{{ $field }}</dt><dd>{{ $reason }}</dd>@endforeach</dl></td></tr>
        @endforeach
        </tbody></table></div>
    </details>
</x-panel>
<x-panel title="Recorded market input coverage" subtitle="Structurally checked reads are not proof of complete execution coverage. Publication, retrieval and local availability remain distinct." icon="clock" flush>
    <div class="table-wrap"><table class="data"><thead><tr><th>Symbol / origin</th><th>Coverage / blocker</th><th>Times / evidence</th></tr></thead><tbody>
    @forelse($execution['inputs'] as $input)
        <tr><td>{{ $input->symbol }} · {{ $input->origin }}</td><td>{{ $input->status }}<small class="block">{{ $input->reason ?? 'Synthetic fixture only' }}</small></td><td><x-time :at="$input->source_at" fallback="Source time unknown" /><small class="block">Retrieved <x-time :at="$input->retrieved_at" /></small><small class="block">Stored <x-time :at="$input->recorded_at" /></small><details><summary>Normalized source evidence</summary><pre class="code-block">{{ json_encode($input->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre></details></td></tr>
    @empty<tr><td colspan="3" class="empty-cell">No normalized market inputs recorded. No provider readiness is claimed.</td></tr>@endforelse
    </tbody></table></div>{{ $execution['inputs']->links('mcp.pagination') }}
    @if(config('paper.market_inputs_enabled'))
    <form method="post" action="{{ route('mcp.paper.market.collect') }}" class="panel-body">
        @csrf
        <input type="hidden" name="account_scope" value="{{ App\Services\Monitoring\AccountScope::key($connection) }}"><input type="hidden" name="request_id" value="{{ Illuminate\Support\Str::uuid() }}">
        <label class="field"><span>Exact symbol for reviewed read-only quote/identity collection</span><input name="symbol" maxlength="16" required pattern="[A-Z][A-Z0-9.\-]*"></label>
        <button class="btn btn-secondary" type="submit">Collect read-only market evidence</button>
    </form>
    @endif
</x-panel>
<x-panel title="Proposal-to-paper-order outcomes" subtitle="An auditable handoff can create a synthetic paper intent, record WAIT/HOLD, or reject unavailable inputs. It never sends a broker order." icon="clock" flush>
    <div class="table-wrap"><table class="data"><thead><tr><th>Symbol / outcome</th><th>Paper order / execution version</th><th>Evidence</th></tr></thead><tbody>
    @forelse($execution['handoffs'] as $handoff)
        <tr><td>{{ $handoff->symbol }} · {{ $handoff->status }}<small class="block">{{ $handoff->reason }}</small></td><td>{{ $handoff->sim_order_id ?? 'No order' }}<small class="block">Execution record #{{ $handoff->execution_version_id ?? 'Unavailable' }}</small></td><td><x-time :at="$handoff->created_at" /><details><summary>Original proposal / sizing / provenance</summary><pre class="code-block">{{ json_encode($handoff->evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre></details></td></tr>
    @empty<tr><td colspan="3" class="empty-cell">No paper handoffs recorded.</td></tr>@endforelse
    </tbody></table></div>{{ $execution['handoffs']->links('mcp.pagination') }}
</x-panel>
