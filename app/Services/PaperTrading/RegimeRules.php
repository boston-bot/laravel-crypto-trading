<?php

declare(strict_types=1);

namespace App\Services\PaperTrading;

use App\Support\Mcp\Decimal;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Pure regime playbook: whether one product is in a trend on the prior close, and the fraction of equity to hold.
 * Signals: `sma_above` (close above its N-day simple average) or `return_positive` (close above the close N days earlier).
 * An optional volatility target scales the held fraction to min(1, target ÷ annualized realized volatility).
 * Exact decimals throughout; the caller establishes freshness, provenance and account scope.
 */
final class RegimeRules
{
    public const VERSION = 'regime_rules_v1';

    private const SCALE = 40;

    /** @param list<array> $bars [date, o, h, l, c, v] oldest first, ending with the last completed day */
    /** Completed days the rules need: N closes for an N-day average, N + 1 for an N-day return, window + 1 for volatility. */
    public static function needed(array $p): int
    {
        return max($p['regime_signal'] === 'return_positive' ? $p['regime_lookback'] + 1 : $p['regime_lookback'], $p['vol_target_pct'] === null ? 0 : $p['vol_window'] + 1);
    }

    public function target(array $bars, array $p): array
    {
        if (count($bars) < self::needed($p)) {
            return ['status' => 'unavailable', 'reason' => 'regime_history_insufficient', 'in_trend' => null, 'fraction' => null, 'facts' => []];
        }
        $closes = array_map(fn ($bar) => BigDecimal::of($bar[4]), $bars);
        $close = $closes[array_key_last($closes)];
        if ($p['regime_signal'] === 'sma_above') {
            $window = array_slice($closes, -$p['regime_lookback']);
            $reference = array_reduce($window, fn (BigDecimal $c, BigDecimal $v) => $c->plus($v), BigDecimal::zero())->dividedBy($p['regime_lookback'], self::SCALE, RoundingMode::HALF_EVEN);
        } elseif ($p['regime_signal'] === 'return_positive') {
            $reference = $closes[count($closes) - 1 - $p['regime_lookback']];
        } else {
            throw new \InvalidArgumentException('Unsupported regime signal.');
        }
        $inTrend = $close->isGreaterThan($reference);
        $facts = ['date' => $bars[array_key_last($bars)][0], 'close' => (string) $close, 'reference' => (string) $reference->toScale(18, RoundingMode::HALF_EVEN)];
        $fraction = BigDecimal::one();
        if ($p['vol_target_pct'] !== null) {
            $volatility = $this->volatility(array_slice($closes, -($p['vol_window'] + 1)));
            $facts['annualized_volatility'] = (string) $volatility->toScale(18, RoundingMode::HALF_EVEN);
            $target = BigDecimal::of($p['vol_target_pct'])->dividedBy(100, self::SCALE);
            if ($volatility->isPositive() && $target->isLessThan($volatility)) {
                $fraction = $target->dividedBy($volatility, 18, RoundingMode::DOWN);
            }
        }

        return ['status' => 'complete', 'reason' => $inTrend ? 'regime_in_trend' : 'regime_out_of_trend', 'in_trend' => $inTrend,
            'fraction' => $inTrend ? (string) $fraction->toScale(18, RoundingMode::DOWN) : '0', 'facts' => $facts];
    }

    /** BUY or SELL to move toward the target, or null inside the band. Entering from flat or exiting to flat always trades. */
    public static function rebalance(string $current, string $target, string $bandPct): ?string
    {
        $current = Decimal::from($current);
        $target = Decimal::from($target);
        $zero = Decimal::from('0');
        if ($target->compareTo($zero) === 0) {
            return $current->compareTo($zero) > 0 ? 'SELL' : null;
        }
        if ($current->compareTo($zero) === 0) {
            return 'BUY';
        }
        $gap = $target->minus($current);
        $band = Decimal::from($bandPct)->dividedBy(Decimal::from('100'), 18);
        if ($gap->compareTo($band) > 0) {
            return 'BUY';
        }

        return $zero->minus($gap)->compareTo($band) > 0 ? 'SELL' : null;
    }

    /** Sample standard deviation of daily log returns, annualized over 365 days. */
    private function volatility(array $closes): BigDecimal
    {
        $returns = [];
        for ($i = 1; $i < count($closes); $i++) {
            $returns[] = $this->ln($closes[$i]->dividedBy($closes[$i - 1], self::SCALE, RoundingMode::HALF_EVEN));
        }
        $n = count($returns);
        $mean = array_reduce($returns, fn (BigDecimal $c, BigDecimal $r) => $c->plus($r), BigDecimal::zero())->dividedBy($n, self::SCALE, RoundingMode::HALF_EVEN);
        $variance = array_reduce($returns, fn (BigDecimal $c, BigDecimal $r) => $c->plus($r->minus($mean)->power(2)), BigDecimal::zero())
            ->dividedBy($n - 1, self::SCALE, RoundingMode::HALF_EVEN);

        return $variance->multipliedBy(365)->sqrt(self::SCALE, RoundingMode::DOWN);
    }

    /** Natural logarithm by the atanh series: ln x = 2 Σ y^(2k+1)/(2k+1), y = (x−1)/(x+1). Converges for every x > 0. */
    private function ln(BigDecimal $x): BigDecimal
    {
        if (! $x->isPositive()) {
            throw new \InvalidArgumentException('Logarithm of a non-positive value.');
        }
        $y = $x->minus(1)->dividedBy($x->plus(1), self::SCALE, RoundingMode::HALF_EVEN);
        $square = $y->multipliedBy($y)->toScale(self::SCALE, RoundingMode::HALF_EVEN);
        $term = $y;
        $sum = BigDecimal::zero();
        $epsilon = BigDecimal::of('0.'.str_repeat('0', self::SCALE - 1).'1');
        for ($k = 0; $k < 5000; $k++) {
            $part = $term->dividedBy(2 * $k + 1, self::SCALE, RoundingMode::HALF_EVEN);
            if ($part->abs()->isLessThan($epsilon)) {
                break;
            }
            $sum = $sum->plus($part);
            $term = $term->multipliedBy($square)->toScale(self::SCALE, RoundingMode::HALF_EVEN);
        }

        return $sum->multipliedBy(2);
    }
}
