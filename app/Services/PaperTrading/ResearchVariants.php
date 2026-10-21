<?php

declare(strict_types=1);

namespace App\Services\PaperTrading;

use App\Services\Broker\Agentic\RobinhoodOrderContract;
use App\Support\Mcp\Decimal;

/**
 * Reviewed bounds for research-only rule and order-mode variants. Variants only change retrospective replays and
 * experiments; they never change live parameters, owner settings, risk limits or the forward ledger.
 */
final class ResearchVariants
{
    public const DECIMALS = ['initial_stop_atr' => ['1', '5'], 'trailing_stop_atr' => ['1', '8'], 'max_chase_atr' => ['0.1', '2']];

    public const SESSIONS = ['time_exit_sessions' => [1, 60], 'cooldown_sessions' => [1, 20], 'market_filter_sma_sessions' => [50, 200]];

    /** Live behavior is true for both; a variant may switch the exit or the entry block off. */
    public const FLAGS = ['exit_on_fast_sma_close', 'market_filter_required'];

    public const ORDER_MODES = ['whole_share_limit', 'fractional_market'];

    /** Research assumption for market-order fills, deliberately above the limit-order slippage; versioned with each run. */
    public const MARKET_SLIPPAGE_BPS = '5';

    public const MARKET_SLIPPAGE_BOUNDS = ['1', '50'];

    /** @return list<string> */
    public static function parameterKeys(): array
    {
        return [...array_keys(self::DECIMALS), ...array_keys(self::SESSIONS), ...self::FLAGS];
    }

    /** Validates typed overrides: decimals as quoted strings, sessions as integers, flags as booleans. */
    public static function parameters(array $parameters): array
    {
        abort_if(array_diff(array_keys($parameters), self::parameterKeys()), 422, 'Unsupported research parameter.');
        foreach ($parameters as $key => $value) {
            if (isset(self::DECIMALS[$key])) {
                [$min, $max] = self::DECIMALS[$key];
                abort_unless(is_string($value) && preg_match('/^\d{1,2}(?:\.\d{1,4})?$/D', $value) &&
                    Decimal::from($value)->compareTo(Decimal::from($min)) >= 0 && Decimal::from($value)->compareTo(Decimal::from($max)) <= 0, 422, 'Research decimal parameter outside reviewed bounds.');
            } elseif (isset(self::SESSIONS[$key])) {
                [$min, $max] = self::SESSIONS[$key];
                abort_unless(is_int($value) && $value >= $min && $value <= $max, 422, 'Research session parameter outside bounds.');
            } else {
                abort_unless(is_bool($value), 422, 'Research switches must be true or false.');
            }
        }

        return $parameters;
    }

    /** Order mode for the daily replay. Fractional quantities exist only as regular-hours market orders. */
    public static function execution(array $execution): array
    {
        abort_if(array_diff(array_keys($execution), ['order_mode', 'market_slippage_bps']), 422, 'Unsupported research order setting.');
        $mode = $execution['order_mode'] ?? 'whole_share_limit';
        abort_unless(in_array($mode, self::ORDER_MODES, true), 422, 'Unsupported research order mode.');
        if ($mode === 'whole_share_limit') {
            abort_if(isset($execution['market_slippage_bps']), 422, 'Market slippage applies only to market orders.');

            return ['order_mode' => $mode];
        }
        abort_unless(RobinhoodOrderContract::allowsFractional('market'), 422, 'The reviewed broker contract does not allow fractional market orders.');
        $slippage = $execution['market_slippage_bps'] ?? self::MARKET_SLIPPAGE_BPS;
        [$min, $max] = self::MARKET_SLIPPAGE_BOUNDS;
        abort_unless(is_string($slippage) && preg_match('/^\d{1,2}(?:\.\d{1,2})?$/D', $slippage) &&
            Decimal::from($slippage)->compareTo(Decimal::from($min)) >= 0 && Decimal::from($slippage)->compareTo(Decimal::from($max)) <= 0, 422, 'Market slippage outside research bounds.');

        return ['order_mode' => $mode, 'market_slippage_bps' => $slippage];
    }

    /** Converts operator text from a form or command into the typed value, leaving validation to parameters(). */
    public static function typed(string $key, string $value): mixed
    {
        if (isset(self::SESSIONS[$key])) {
            return preg_match('/^[1-9]\d{0,2}$/D', $value) ? (int) $value : $value;
        }
        if (in_array($key, self::FLAGS, true)) {
            return match ($value) {
                'true', 'yes', '1' => true,
                'false', 'no', '0' => false,
                default => $value,
            };
        }

        return $value;
    }
}
