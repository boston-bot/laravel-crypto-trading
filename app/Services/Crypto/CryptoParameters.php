<?php

declare(strict_types=1);

namespace App\Services\Crypto;

/** Independent research hypothesis, not an equity parameter alias or a proven profitable policy. */
final class CryptoParameters
{
    public const VERSION = 'crypto_swing_research_v1';

    public static function current(): array
    {
        $values = ['asset_class' => 'crypto', 'venue' => 'coinbase', 'day_timezone' => 'UTC', 'price_scale' => 18,
            'benchmark_symbol' => 'BTC-USD', 'market_filter_sma_sessions' => 100, 'fast_sma_sessions' => 30,
            'slow_sma_sessions' => 100, 'relative_strength_sessions' => 60, 'atr_sessions' => 14, 'max_atr_pct' => '12',
            'ema_sessions' => 20, 'pullback_max_atr_from_ema' => '1', 'breakout_range_sessions' => 20,
            'breakout_max_range_atr' => '4', 'max_chase_atr' => '0.25', 'initial_stop_atr' => '2.5',
            'trailing_stop_atr' => '3.5', 'time_exit_sessions' => 14, 'cooldown_sessions' => 2,
            'min_history_days' => 200, 'max_spread_bps' => '50', 'min_quote_volume_24h' => '10000000',
            'quote_max_age_seconds' => 120, 'metadata_max_age_seconds' => 900, 'slippage_bps' => '10',
            'relative_strength_inclusive' => true];

        return ['version' => self::VERSION, 'values' => $values, 'hash' => hash('sha256', json_encode($values, JSON_THROW_ON_ERROR))];
    }
}
