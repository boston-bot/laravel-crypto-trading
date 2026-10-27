<?php

declare(strict_types=1);

namespace App\Services\Crypto;

/**
 * Versioned crypto playbooks. Each is a fixed research hypothesis, not an equity parameter alias or a proven policy.
 * The swing version uses the shared swing rules; the regime versions use the shared regime rules on one product.
 */
final class CryptoParameters
{
    public const VERSION = 'crypto_swing_research_v1';

    /** Pre-registered October 27 research candidates T4 and R1 (CRYPTO-C3-PAPER-EVIDENCE.md). */
    public const REGIME_TREND = 'crypto_regime_trend_v1';

    public const REGIME_MOMENTUM = 'crypto_regime_momentum_v1';

    public static function current(): array
    {
        return self::named(self::VERSION);
    }

    public static function named(string $version): array
    {
        $common = ['asset_class' => 'crypto', 'venue' => 'coinbase', 'day_timezone' => 'UTC', 'price_scale' => 18, 'benchmark_symbol' => 'BTC-USD',
            'max_spread_bps' => '50', 'min_quote_volume_24h' => '10000000', 'quote_max_age_seconds' => 120, 'metadata_max_age_seconds' => 900, 'slippage_bps' => '10'];
        $values = match ($version) {
            self::VERSION => ['asset_class' => 'crypto', 'venue' => 'coinbase', 'day_timezone' => 'UTC', 'price_scale' => 18,
                'benchmark_symbol' => 'BTC-USD', 'market_filter_sma_sessions' => 100, 'fast_sma_sessions' => 30,
                'slow_sma_sessions' => 100, 'relative_strength_sessions' => 60, 'atr_sessions' => 14, 'max_atr_pct' => '12',
                'ema_sessions' => 20, 'pullback_max_atr_from_ema' => '1', 'breakout_range_sessions' => 20,
                'breakout_max_range_atr' => '4', 'max_chase_atr' => '0.25', 'initial_stop_atr' => '2.5',
                'trailing_stop_atr' => '3.5', 'time_exit_sessions' => 14, 'cooldown_sessions' => 2,
                'min_history_days' => 200, 'max_spread_bps' => '50', 'min_quote_volume_24h' => '10000000',
                'quote_max_age_seconds' => 120, 'metadata_max_age_seconds' => 900, 'slippage_bps' => '10',
                'relative_strength_inclusive' => true],
            // T4: BTC above its 200-day average, held at min(1, 50% ÷ 30-day annualized volatility), adjusted outside a 20-point band.
            self::REGIME_TREND => $common + ['playbook' => 'regime', 'product' => 'BTC-USD', 'regime_signal' => 'sma_above', 'regime_lookback' => 200,
                'vol_target_pct' => '50', 'vol_window' => 30, 'rebalance_band_pct' => '20', 'min_history_days' => 200],
            // R1: BTC above its close 365 days earlier, fully held, otherwise cash. A 100-point band means enter and exit only, never resize.
            self::REGIME_MOMENTUM => $common + ['playbook' => 'regime', 'product' => 'BTC-USD', 'regime_signal' => 'return_positive', 'regime_lookback' => 365,
                'vol_target_pct' => null, 'vol_window' => 30, 'rebalance_band_pct' => '100', 'min_history_days' => 366],
            default => throw new \InvalidArgumentException('Unknown crypto parameter version.'),
        };

        return ['version' => $version, 'values' => $values, 'hash' => hash('sha256', json_encode($values, JSON_THROW_ON_ERROR))];
    }

    public static function regime(array $parameters): bool
    {
        return ($parameters['values']['playbook'] ?? null) === 'regime';
    }
}
