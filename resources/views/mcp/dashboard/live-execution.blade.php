@php
    $scopeKey = App\Services\Monitoring\AccountScope::key($connection);
    $policy = $live['policy'];
    $controls = $live['controls'];
    $labels = ['sleeve_capital' => 'Live sleeve capital (USD)', 'max_order_notional' => 'Maximum order value (USD)',
        'risk_per_trade_pct' => 'Risk per trade (%) · maximum 1', 'max_position_pct' => 'Maximum position (% of sleeve) · maximum 20',
        'max_open_positions' => 'Maximum open positions · 1–8', 'daily_loss_pause_pct' => 'Daily loss pause (%) · maximum 10',
        'max_orders_per_session' => 'Maximum orders per session · 1–20', 'slippage_bps' => 'Adverse slippage (basis points) · market minimum 5',
        'limit_offset_bps' => 'Limit offset (basis points)', 'quote_max_age_seconds' => 'Maximum quote age (seconds) · 1–60'];
    $saved = $policy?->payload ?? [];
@endphp
<x-panel title="Live execution boundary" subtitle="Owner policy, controls and broker-confirmed outcomes. Separate from paper trading." icon="shield">
    @if($controls['emergency_disabled'])
        <x-callout tone="bad" title="Emergency disable engaged">Every live claim is denied, buys and sells. A claimed order not yet sent is withdrawn.</x-callout>
    @endif
    <x-callout tone="warn" title="{{ $live['writes_enabled'] ? 'Brokerage writes enabled by configuration' : 'Brokerage writes disabled' }}">Automatic execution requires the separate account switch above. Applying a live policy does not enable it; changing the policy requires enabling automatic trading again.</x-callout>
    <dl class="kv kv-grid">
        <dt>Live policy</dt><dd>{{ $policy ? 'Version '.$policy->version.' · applied ' : 'None applied: every live claim is denied' }}@if($policy)<x-time :at="$policy->applied_at" /> · fingerprint <code>{{ substr($policy->fingerprint, 0, 12) }}</code>@endif</dd>
        <dt>Buy pause</dt><dd><x-pill :tone="$controls['buy_paused'] ? 'warn' : 'ok'">{{ $controls['buy_paused'] ? 'New entries paused' : 'Not paused' }}</x-pill></dd>
        <dt>Emergency disable</dt><dd><x-pill :tone="$controls['emergency_disabled'] ? 'bad' : 'ok'">{{ $controls['emergency_disabled'] ? 'Engaged' : 'Off' }}</x-pill></dd>
        <dt>External-order entry gate</dt><dd>{{ $live['entry_pause_reason'] ?? 'No external-order pause' }}</dd>
    </dl>

    @unless($controls['emergency_disabled'])
        <form method="post" action="{{ route('mcp.live.controls') }}">@csrf
            <input type="hidden" name="account_scope" value="{{ $scopeKey }}">
            <input type="hidden" name="request_id" value="{{ Illuminate\Support\Str::uuid() }}">
            <input type="hidden" name="buy_paused" value="{{ $controls['buy_paused'] ? 1 : 0 }}">
            <input type="hidden" name="emergency_disabled" value="1">
            <input type="hidden" name="reason" value="Emergency disable from the Control Center.">
            <button class="btn btn-danger" type="submit">Engage emergency disable</button>
        </form>
    @endunless
    <details>
        <summary>Change buy pause or emergency disable</summary>
        <form method="post" action="{{ route('mcp.live.controls') }}" class="stack">@csrf
            <input type="hidden" name="account_scope" value="{{ $scopeKey }}">
            <input type="hidden" name="request_id" value="{{ Illuminate\Support\Str::uuid() }}">
            <label class="field">Buy pause<select name="buy_paused">
                <option value="1" @selected($controls['buy_paused'])>Pause new entries</option>
                <option value="0" @selected(! $controls['buy_paused'])>Allow new entries (other checks still apply)</option>
            </select></label>
            <label class="field">Emergency disable<select name="emergency_disabled">
                <option value="1" @selected($controls['emergency_disabled'])>Engaged</option>
                <option value="0" @selected(! $controls['emergency_disabled'])>Off</option>
            </select></label>
            <label class="field">Reason<input name="reason" required maxlength="200"></label>
            <p class="note">Releasing a control requires the current connected authorization. Engaging one never does.</p>
            <button class="btn btn-secondary" type="submit">Record controls</button>
        </form>
    </details>

    <details @if(! $policy) open @endif>
        <summary>{{ $policy ? 'Apply a new live policy version' : 'Apply the first live policy' }}</summary>
        <form method="post" action="{{ route('mcp.live.policy') }}" class="stack">@csrf
            <input type="hidden" name="account_scope" value="{{ $scopeKey }}">
            <input type="hidden" name="base_version" value="{{ $policy?->version ?? 0 }}">
            <input type="hidden" name="request_id" value="{{ Illuminate\Support\Str::uuid() }}">
            @foreach($labels as $field => $label)
                <label class="field">{{ $label }}<input name="live[{{ $field }}]" required inputmode="decimal" value="{{ old('live.'.$field, $saved[$field] ?? '') }}"></label>
            @endforeach
            @php $type = old('live.order_type', $saved['order_type'] ?? 'market'); @endphp
            <label class="field">Order type<select name="live[order_type]">
                <option value="market" @selected($type === 'market')>Market (entry quote checked against the profile maximum; fill price can vary)</option>
                <option value="limit" @selected($type === 'limit')>Limit (whole shares)</option>
            </select></label>
            @php $approvalMode = (string) old('live.require_broker_approval', isset($saved['require_broker_approval']) ? ($saved['require_broker_approval'] ? '1' : '0') : ''); @endphp
            <label class="field">Robinhood trade approval (set by you in the Robinhood app)<select name="live[require_broker_approval]" required>
                <option value="" @selected($approvalMode === '')>Choose the mode you expect</option>
                <option value="1" @selected($approvalMode === '1')>On: every order waits for my approval in the app (recommended for a pilot)</option>
                <option value="0" @selected($approvalMode === '0')>Off: orders execute without my approval</option>
            </select></label>
            <p class="note">Every live order is denied while the broker's current setting differs from this choice or cannot be read.</p>
            <label class="check"><input type="checkbox" name="live[allow_fractional]" value="1" @checked(old('live.allow_fractional', $saved['allow_fractional'] ?? false))><span>Fractional shares on market orders when Robinhood reports the symbol fractional-tradable</span></label>
            <details><summary>Reviewed cost schedule attached to the policy</summary><pre class="code-block">{{ json_encode(App\Services\PaperTrading\TradingCosts::reviewed(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre></details>
            <label class="check"><input type="checkbox" name="confirm_live_policy" value="1" required><span>I set these live limits for this broker account. Applying them does not enable writes and invalidates intents staged under an earlier version.</span></label>
            <button class="btn btn-secondary" type="submit">Apply live policy version</button>
        </form>
    </details>
    @if($policy)<details><summary>Applied live settings</summary><pre class="code-block">{{ json_encode($policy->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre></details>@endif

    @foreach($live['incidents'] as $incident)
        <x-callout tone="bad" title="Reconciliation review required">{{ $incident->incident_key }} · last seen <x-time :at="$incident->last_seen_at" />. Every live claim is denied until reviewed.</x-callout>
        <form method="post" action="{{ route('mcp.live.review') }}" class="stack">@csrf
            <input type="hidden" name="account_scope" value="{{ $scopeKey }}">
            <input type="hidden" name="incident_key" value="{{ $incident->incident_key }}">
            <label class="check"><input type="checkbox" name="confirm_reconciliation" value="1" required><span>I compared the broker's order history with the journal. If the contradiction persists, the next read reopens this block.</span></label>
            <button class="btn btn-secondary" type="submit">Record reconciliation review</button>
        </form>
    @endforeach

    @foreach($live['unknown'] as $row)
        @php $p = $row['intent']->payload; @endphp
        @if($row['status'] === 'approval_pending')
            <x-callout tone="warn" title="Waiting for your approval · intent #{{ $row['intent']->id }}">{{ $p['side'] }} {{ $p['quantity'] }} {{ $p['symbol'] }} was sent to Robinhood, which asked you to approve or decline it in the Robinhood app (approval expires <x-time :at="$row['approval']?->expires_at" />). New live orders are blocked until the approved order appears in order history with this app's reference, or you confirm no order after the approval expires. An order you edit before approving is treated as your own order.</x-callout>
        @else
            <x-callout tone="warn" title="Unknown outcome · intent #{{ $row['intent']->id }}">{{ $p['side'] }} {{ $p['quantity'] }} {{ $p['symbol'] }} was claimed <x-time :at="$row['claimed_at']" /> and the broker response was not recorded. New live orders are blocked until the broker's order is matched by this app's reference, you link it, or you confirm none exists. It is never retried.</x-callout>
        @endif
        @if($row['candidates']->isNotEmpty())
            <form method="post" action="{{ route('mcp.live.link', $row['intent']->id) }}" class="stack">@csrf
                <input type="hidden" name="account_scope" value="{{ $scopeKey }}">
                <input type="hidden" name="request_id" value="{{ Illuminate\Support\Str::uuid() }}">
                <label class="field">Observed broker order<select name="external_order_id" required>
                    @foreach($row['candidates'] as $candidate)
                        @php $o = $candidate->payload['order']; @endphp
                        <option value="{{ $candidate->id }}">{{ $o['symbol'] ?? '?' }} {{ $o['side'] ?? '?' }} {{ $o['quantity'] ?? '?' }} · {{ $o['status'] ?? 'unknown' }} · created {{ $o['created_at'] ?? 'unknown' }} · {{ $o['id'] }}</option>
                    @endforeach
                </select></label>
                <label class="field">Note<input name="note" required maxlength="500"></label>
                <label class="check"><input type="checkbox" name="confirm_link" value="1" required><span>This broker order is the one this intent created. Linking also records my review of it as an external order.</span></label>
                <button class="btn btn-secondary" type="submit">Link broker order</button>
            </form>
        @endif
        <form method="post" action="{{ route('mcp.live.absent', $row['intent']->id) }}" class="stack">@csrf
            <input type="hidden" name="account_scope" value="{{ $scopeKey }}">
            <label class="check"><input type="checkbox" name="confirm_absent" value="1" required><span>The broker created no order for this intent. Allowed only with a complete order-history read at least two minutes after the deadline (and any approval expiry) that shows no matching order.</span></label>
            <button class="btn btn-secondary" type="submit">Confirm no broker order</button>
        </form>
    @endforeach

    <h3>Recent live intents</h3>
    @forelse($live['intents'] as $row)
        @php $p = $row['intent']->payload; @endphp
        <p>#{{ $row['intent']->id }} · {{ $p['side'] }} <x-quantity :value="$p['quantity']" /> {{ $p['symbol'] }} · {{ $p['type'] }} · collar <x-money :value="$p['price_bound']" /> · <x-pill :value="$row['status']" />
            @if($row['outcome']) · broker {{ $row['outcome']->broker_state }}, filled <x-quantity :value="(string) $row['outcome']->filled_quantity" /> as of <x-time :at="$row['outcome']->retrieved_at" />@endif</p>
    @empty
        <p class="note">No live intents recorded.</p>
    @endforelse

    <h3>Broker-confirmed fills</h3>
    @forelse($live['fills'] as $fill)
        <p>{{ $fill->side }} <x-quantity :value="(string) $fill->quantity" /> {{ $fill->symbol }} at <x-money :value="(string) $fill->price" /> · fees <x-money :value="(string) $fill->fees" /> · <x-time :at="$fill->executed_at" /></p>
    @empty
        <p class="note">No broker-confirmed fills. Paper fills are shown only on the Paper Trading dashboard.</p>
    @endforelse
</x-panel>
