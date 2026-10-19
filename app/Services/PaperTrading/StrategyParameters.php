<?php

declare(strict_types=1);

namespace App\Services\PaperTrading;

/**
 * Versioned starting parameters from the M5 design. They are code defaults until the owner parameter editor exists;
 * watchlist contents and model text can never change them. Decimal values are quoted strings.
 */
final class StrategyParameters
{
    public const VERSION = 'swing_parameters_v1_defaults';

    public const DEFAULTS = [
        'benchmark_symbol' => 'SPY',
        'market_filter_sma_sessions' => 200,
        'fast_sma_sessions' => 50,
        'slow_sma_sessions' => 200,
        'relative_strength_sessions' => 126,
        'atr_sessions' => 14,
        'max_atr_pct' => '6',
        'ema_sessions' => 20,
        'pullback_max_atr_from_ema' => '1',
        'breakout_range_sessions' => 20,
        'breakout_max_range_atr' => '3',
        'max_chase_atr' => '0.5',
        'initial_stop_atr' => '2',
        'trailing_stop_atr' => '3',
        'earnings_blackout_sessions' => 5,
    ];

    public static function current(): array
    {
        return ['version' => self::VERSION, 'values' => self::DEFAULTS, 'hash' => hash('sha256', json_encode(self::DEFAULTS, JSON_THROW_ON_ERROR))];
    }
}
