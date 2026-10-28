@php
    $automaticScope = App\Services\Monitoring\AccountScope::key($connection);
    $armed = $automatic['state']?->enabled && $automatic['reason'] === null;
@endphp
<x-panel title="Automatic trading" subtitle="Real orders under your saved live policy" icon="shield">
    <div class="form-actions">
        <x-pill :tone="$automatic['blocking_orders']->isNotEmpty() ? 'warn' : ($armed ? 'ok' : 'neutral')">{{ $automatic['blocking_orders']->isNotEmpty() ? 'Submissions blocked · order reconciliation' : ($armed ? ($automatic['controls']['buy_paused'] ? 'Exits only · buys paused' : 'On · buys and exits') : 'Off / blocked') }}</x-pill>
        <form method="post" action="{{ route('mcp.live.automatic.stop') }}" class="inline-form">@csrf
            <input type="hidden" name="account_scope" value="{{ $automaticScope }}">
            <input type="hidden" name="request_id" value="{{ Illuminate\Support\Str::uuid() }}">
            <button class="btn btn-danger" type="submit">Stop all submissions</button>
        </form>
        <form method="post" action="{{ route('mcp.live.buys.pause') }}" class="inline-form">@csrf
            <input type="hidden" name="account_scope" value="{{ $automaticScope }}">
            <input type="hidden" name="request_id" value="{{ Illuminate\Support\Str::uuid() }}">
            <input type="hidden" name="paused" value="{{ $automatic['controls']['buy_paused'] ? '0' : '1' }}">
            <button class="btn btn-secondary" type="submit">{{ $automatic['controls']['buy_paused'] ? 'Resume new buys' : 'Pause new buys' }}</button>
        </form>
    </div>
    <p class="note">Stop blocks new buys and sells. An order already being submitted or accepted by Robinhood can still fill; cancel it in Robinhood if needed. Pausing buys leaves automatic exits running while trading is on.</p>
    @if($automatic['blocking_orders']->isNotEmpty())
        <p role="status">An unresolved or open submission blocks every new live order, including exits. Monitoring continues. Reviewing an external-order alert alone does not resolve its execution intent.</p>
        <p>@foreach($automatic['blocking_orders'] as $blocking) Intent #{{ $blocking->id }} · {{ App\Support\Mcp\DisplayFormat::humanize($blocking->status) }}. @endforeach
            <a href="{{ route('mcp.dashboard', ['page' => 'control']) }}#live-reconciliation">Review and reconcile broker orders</a></p>
    @endif
    @if($automatic['state']?->enabled && $automatic['reason'])
        <p role="status">Trading blocked: {{ App\Support\Mcp\DisplayFormat::humanize($automatic['reason']) }}. Review the policy and authorization before enabling again.</p>
    @endif
    @if($automatic['last_cycle'])
        <p class="note">Last cycle: <x-time :at="$automatic['last_cycle']->started_at" /> · {{ $automatic['last_cycle']->status }}@if($automatic['last_cycle']->reason) · {{ App\Support\Mcp\DisplayFormat::humanize($automatic['last_cycle']->reason) }}@endif · {{ $automatic['last_cycle']->evaluated }} evaluated · {{ $automatic['last_cycle']->attempted }} attempted</p>
    @endif
    @unless($armed)
        @if($automatic['configuration_reason'])
            <p class="note">Automatic execution is disabled in runtime configuration. Monitoring continues.</p>
        @else
            <details><summary>Enable automatic trading</summary>
                <form method="post" action="{{ route('mcp.live.automatic.start') }}" class="stack">@csrf
                    <input type="hidden" name="account_scope" value="{{ $automaticScope }}">
                    <input type="hidden" name="request_id" value="{{ Illuminate\Support\Str::uuid() }}">
                    <input type="hidden" name="base_version" value="{{ $automatic['state']?->version ?? 0 }}">
                    <label class="check"><input type="checkbox" name="confirm_exclusive_executor" value="1" required><span>I have paused other trading tasks/executors for this account; this app is its sole executor.</span></label>
                    <label class="check"><input type="checkbox" name="confirm_automatic" value="1" required><span>Use my current live policy to submit real orders automatically. My Robinhood approval setting determines whether they need individual approval.</span></label>
                    <button class="btn btn-primary" type="submit">Enable automatic trading</button>
                </form>
            </details>
        @endif
    @endunless
</x-panel>
