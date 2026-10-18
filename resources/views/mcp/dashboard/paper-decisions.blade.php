<x-panel title="Paper AI decisions" subtitle="Non-executable proposals. A recorded window or WAIT is distinct from a fresh model call, a skipped call or an order." icon="spark" id="paper-ai-decisions">
    <dl class="kv kv-grid">
        <dt>Readiness</dt><dd>{{ $decisions['reason'] ?? 'Configured for current regular-session proposals' }}</dd>
        <dt>Applied target interval</dt><dd>{{ $decisions['interval_minutes'] === null ? 'Unspecified' : $decisions['interval_minutes'].' minutes' }}</dd>
        <dt>Next eligible window</dt><dd><x-time :at="$decisions['next_eligible_at']" fallback="Unavailable or outside verified session" /></dd>
        <dt>Last recorded call attempt</dt><dd><x-time :at="$decisions['last_call_at']" fallback="No recorded attempt" /></dd>
        <dt>Last recorded response</dt><dd><x-time :at="$decisions['last_response_at']" fallback="No recorded response" /></dd>
        <dt>Last observed attempt gap</dt><dd>{{ $decisions['observed_gap_seconds'] === null ? 'Unknown; fewer than two attempts' : $decisions['observed_gap_seconds'].' seconds' }}</dd>
        <dt>Current input adapter</dt><dd>Stored account evidence and reviewed adapter records when execution settings are applied. Broad screening/news ingestion is not connected. Unknown eligibility, size and source times remain blockers.</dd>
    </dl>
    <p class="note">Unknown source times, positions and coverage stay unknown. The existing local scheduler dispatches only the current window when explicitly enabled; missed windows are not replayed. AI/data reservations share the application ledger and respect the tighter applied paper envelope. No invoice or response-time guarantee is implied.</p>
    <details><summary>Current fixed paper-decision instructions · {{ App\Services\PaperTrading\DecisionSchema::INSTRUCTIONS_VERSION }}</summary><pre class="code-block">{{ App\Services\PaperTrading\DecisionSchema::instructions() }}</pre></details>
</x-panel>
<x-panel title="Paper decision history" subtitle="Encrypted request snapshots and bounded responses are available only to this account's operator." icon="clock" flush>
    <div class="table-wrap"><table class="data"><thead><tr><th>Window / status</th><th>Call / model</th><th>Usage / recorded USD</th><th>Request and result</th></tr></thead><tbody>
    @forelse($decisions['runs'] as $item)
        <tr><td><x-time :at="$item->scheduled_at" /><small class="block">{{ $item->status }} · {{ $item->reason ?? 'No rejection recorded' }}</small><small>Expires <x-time :at="$item->expires_at" /></small></td>
            <td>{{ $item->model ?? 'No model call' }}<small class="block"><x-time :at="$item->call_started_at" fallback="No call dispatched" /></small><small>{{ $item->prompt_version }}</small></td>
            <td>{{ $item->input_tokens ?? '?' }} in / {{ $item->output_tokens ?? '?' }} out<small class="block">{{ $item->actual_cost }} · {{ $item->cost_basis ?? 'no call' }}</small><small>Reserved {{ $item->reserved_cost }}</small></td>
            <td><details><summary>Recorded request / applied rules</summary><p>Persisted before a call attempt; this is not proof of network acceptance.</p><p>Ledger version {{ $item->ledger_version }} · context hash <code>{{ $item->context_hash ?? 'No request persisted' }}</code></p><pre class="code-block">{{ json_encode($item->request_snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre></details>
                <details><summary>Response / validation</summary><pre class="code-block">{{ json_encode($item->result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre></details>
                @if(config('paper.handoff_enabled') && in_array($item->status, ['proposed', 'wait'], true))
                <form method="post" action="{{ route('mcp.paper.handoff', ['run' => $item->id]) }}">
                    @csrf
                    <input type="hidden" name="account_scope" value="{{ App\Services\Monitoring\AccountScope::key($connection) }}">
                    <label class="check"><input type="checkbox" name="confirm_paper" value="1" required><span>Evaluate this recorded proposal for paper orders only; recheck current rules and evidence.</span></label>
                    <button class="btn btn-secondary" type="submit">Evaluate paper handoff</button>
                </form>
                @endif
            </td></tr>
    @empty
        <tr><td colspan="4" class="empty-cell">No paper decision windows recorded.</td></tr>
    @endforelse
    </tbody></table></div>{{ $decisions['runs']->links('mcp.pagination') }}
</x-panel>
