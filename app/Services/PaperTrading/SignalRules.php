<?php

declare(strict_types=1);

namespace App\Services\PaperTrading;

use App\Support\Mcp\Decimal;
use Brick\Math\RoundingMode;

/** Pure rules. All prices are quoted decimals. The caller establishes freshness, provenance and account scope. */
final class SignalRules
{
    public const VERSION = 'swing_signals_v1';

    public static function result(string $status, string $reason, ?string $side = null, array $facts = []): array
    {
        return ['status' => $status, 'side' => $side, 'reason' => $reason, 'facts' => $facts];
    }

    /** Research variants may set market_filter_required to false; live parameters never carry the key. */
    public static function marketFilterBlocks(array $profile, array $p): bool
    {
        return ($p['market_filter_required'] ?? true) !== false && ($profile['market']['filter'] ?? null) !== 'on';
    }

    public function entry(array $profile, string $ask, array $p): array
    {
        if ($profile['setup'] === 'none') {
            return self::result('wait', 'no_setup');
        }
        if (! in_array($profile['setup'], ['breakout', 'pullback'], true)) {
            return self::result('unavailable', 'playbook_unknown');
        }
        $price = Decimal::from($ask);
        $trigger = Decimal::from($profile['levels']['trigger']);
        $atr = Decimal::from($profile['indicators']['atr']);
        if ($atr->compareTo(Decimal::from('0')) <= 0) {
            return self::result('unavailable', 'atr_not_positive');
        }
        if ($price->compareTo($trigger) <= 0) {
            return self::result('wait', 'trigger_not_crossed');
        }
        if ($price->compareTo($trigger->plus($atr->multipliedBy(Decimal::from($p['max_chase_atr'])))) > 0) {
            return self::result('wait', 'maximum_chase_exceeded');
        }
        // The profile stop is indicative; an actual fill must pin its own entry-minus-ATR stop at the future handoff.
        $stop = $price->minus($atr->multipliedBy(Decimal::from($p['initial_stop_atr'])))->rounded(4, RoundingMode::UP);
        if ($stop->compareTo(Decimal::from('0')) <= 0 || $stop->compareTo($price) >= 0) {
            return self::result('unavailable', 'initial_stop_invalid');
        }

        return self::result('signal', $profile['setup'].'_trigger_crossed', 'BUY', ['ask' => $ask, 'trigger' => (string) $trigger,
            'entry_atr' => (string) $atr, 'indicative_initial_stop' => (string) $stop]);
    }

    /** Stops take priority even when earnings or daily history are unknown. A missing fact never invents an exit. */
    public function exit(string $bid, array $position, ?array $profile, array $earnings, array $p): array
    {
        $price = Decimal::from($bid);
        $initial = Decimal::from($position['initial_stop']);
        $trail = Decimal::from($position['trailing_stop']);
        $facts = ['bid' => $bid, 'initial_stop' => (string) $initial, 'trailing_stop' => (string) $trail];
        if (($position['latched_exit_signal_id'] ?? null) !== null) {
            return self::result('signal', 'exit_remains_pending', 'SELL', $facts + ['original_signal_id' => $position['latched_exit_signal_id']]);
        }
        if ($price->compareTo($initial) <= 0) {
            return self::result('signal', 'initial_stop_hit', 'SELL', $facts);
        }
        if ($price->compareTo($trail) <= 0) {
            return self::result('signal', 'trailing_stop_hit', 'SELL', $facts);
        }
        // Research variants may switch this exit off with exit_on_fast_sma_close; live parameters never carry the key.
        if (($p['exit_on_fast_sma_close'] ?? true) !== false && $profile !== null && Decimal::from($profile['indicators']['close'])->compareTo(Decimal::from($profile['indicators']['sma_fast'])) < 0) {
            return self::result('signal', 'daily_close_below_fast_sma', 'SELL', $facts);
        }
        if (($position['sessions_held'] ?? null) !== null && $position['sessions_held'] >= $p['time_exit_sessions'] && $position['progressed'] === false) {
            return self::result('signal', 'time_exit_without_progress', 'SELL', $facts);
        }
        if ($earnings['status'] === 'blocked') {
            return self::result('signal', 'last_session_before_earnings', 'SELL', $facts);
        }
        if ($profile === null || ($position['sessions_held'] ?? null) === null || $earnings['status'] === 'unavailable') {
            return self::result('unavailable', 'exit_reference_data_unavailable', facts: $facts);
        }

        return self::result('wait', 'holding_exit_not_triggered', facts: $facts);
    }
}
