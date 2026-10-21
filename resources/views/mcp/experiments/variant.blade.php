<div class="experiment-variant" data-variant>
    <div class="experiment-variant-head">
        <label class="field">Variant ID<input name="variants[{{ $index }}][id]" value="{{ $variant['id'] ?? '' }}" maxlength="32" pattern="[a-z][a-z0-9_]{0,31}" placeholder="e.g. baseline"></label>
        <label class="field">Starting cash (USD)<input name="variants[{{ $index }}][equity]" value="{{ $variant['equity'] ?? '' }}" inputmode="decimal" maxlength="16" placeholder="500"></label>
        <label class="check"><input type="radio" name="baseline_index" value="{{ $index }}" @checked((string) $selectedBaseline === (string) $index) required><span>Baseline</span></label>
        <button type="button" class="btn btn-ghost btn-sm" data-remove-variant hidden>Remove</button>
    </div>
    <details @if(!empty($variant['parameters'])) open @endif>
        <summary>Rule overrides <span class="muted">· optional</span></summary>
        <div class="experiment-parameters">
            @foreach(['initial_stop_atr' => 'Initial stop (ATR)', 'trailing_stop_atr' => 'Trailing stop (ATR)', 'max_chase_atr' => 'Maximum chase (ATR)', 'time_exit_sessions' => 'Time exit (sessions)', 'cooldown_sessions' => 'Cooldown (sessions)'] as $key => $label)
            <label class="field">{{ $label }}<input name="variants[{{ $index }}][parameters][{{ $key }}]" value="{{ $variant['parameters'][$key] ?? '' }}" inputmode="{{ str_ends_with($key, 'sessions') ? 'numeric' : 'decimal' }}" placeholder="Default: {{ App\Services\PaperTrading\SignalParameters::current()['values'][$key] }}" maxlength="16"></label>
            @endforeach
        </div>
    </details>
</div>
