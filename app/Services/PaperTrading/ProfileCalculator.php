<?php

declare(strict_types=1);

namespace App\Services\PaperTrading;

use App\Support\Mcp\Decimal;
use Brick\Math\RoundingMode;

/**
 * Pure daily profile and playbook assignment from completed split-adjusted daily bars ([date, o, h, l, c, v, vw, n],
 * oldest first). Exact decimals only; no network, storage, model text or watchlist fields.
 */
final class ProfileCalculator
{
    // v2: the earnings blocker comes from the supplied calendar result instead of always being unknown.
    public const VERSION = 'swing_profile_v2';

    /** @param array|null $earnings result of EarningsCalendar::status(); null means no earnings source */
    public function compute(array $bars, array $benchmark, array $p, ?array $earnings = null): array
    {
        $scale = $p['price_scale'] ?? 8;
        $levelScale = $p['price_scale'] ?? 4;
        $result = ['profile_version' => self::VERSION, 'session_date' => $bars === [] ? null : $bars[array_key_last($bars)][0],
            'status' => 'unavailable', 'reason' => null, 'setup' => null, 'indicators' => null, 'market' => null, 'checks' => [], 'levels' => null,
            'earnings' => $earnings ?? ['status' => 'unavailable', 'reason' => 'earnings_source_unconfigured'], 'entry_blockers' => []];
        $need = max($p['slow_sma_sessions'], $p['fast_sma_sessions'], $p['relative_strength_sessions'] + 1, $p['atr_sessions'] + 1, $p['breakout_range_sessions'], $p['ema_sessions']);
        if (count($bars) < $need) {
            return array_replace($result, ['reason' => 'insufficient_history']);
        }
        if (count($benchmark) < max($p['market_filter_sma_sessions'], $p['relative_strength_sessions'] + 1)) {
            return array_replace($result, ['reason' => 'benchmark_history_insufficient']);
        }
        // Relative strength compares the same completed session; a lagging series would compare different days.
        if ($benchmark[array_key_last($benchmark)][0] !== $result['session_date']) {
            return array_replace($result, ['reason' => 'benchmark_session_mismatch']);
        }
        $closes = array_map(fn ($bar) => Decimal::from($bar[4]), $bars);
        $close = $closes[array_key_last($closes)];
        $smaFast = $this->sma($closes, $p['fast_sma_sessions'], $scale);
        $smaSlow = $this->sma($closes, $p['slow_sma_sessions'], $scale);
        $ema = $this->ema($closes, $p['ema_sessions'], $scale);
        $atr = $this->atr($bars, $p['atr_sessions'], $scale);
        $return = $this->change($closes, $p['relative_strength_sessions'], $scale);
        $benchCloses = array_map(fn ($bar) => Decimal::from($bar[4]), $benchmark);
        $benchClose = $benchCloses[array_key_last($benchCloses)];
        $benchSma = $this->sma($benchCloses, $p['market_filter_sma_sessions'], $scale);
        $benchReturn = $this->change($benchCloses, $p['relative_strength_sessions'], $scale);
        $range = array_slice($bars, -$p['breakout_range_sessions']);
        $high = $this->extreme($range, 2, 1);
        $low = $this->extreme($range, 3, -1);
        $result['indicators'] = ['close' => (string) $close, 'sma_fast' => (string) $smaFast, 'sma_slow' => (string) $smaSlow, 'ema' => (string) $ema,
            'atr' => (string) $atr, 'return' => (string) $return, 'range_high' => (string) $high, 'range_low' => (string) $low,
            'prior_session_high' => $bars[array_key_last($bars)][2]];
        $filter = $benchClose->compareTo($benchSma) > 0;
        $result['market'] = ['symbol' => $p['benchmark_symbol'], 'close' => (string) $benchClose, 'sma' => (string) $benchSma, 'return' => (string) $benchReturn,
            'filter' => $filter ? 'on' : 'off'];
        $atrOf = fn (string $multiple) => $atr->multipliedBy(Decimal::from($multiple));
        $checks = [
            'uptrend' => $close->compareTo($smaSlow) > 0 && $smaFast->compareTo($smaSlow) > 0,
            'relative_strength' => $return->compareTo($benchReturn) >= (($p['relative_strength_inclusive'] ?? false) ? 0 : 1),
            // ATR / close * 100 <= maximum, cross-multiplied.
            'volatility' => $atr->multipliedBy(Decimal::from('100'))->compareTo(Decimal::from($p['max_atr_pct'])->multipliedBy($close)) <= 0,
            'tight_range' => $high->minus($low)->compareTo($atrOf($p['breakout_max_range_atr'])) <= 0,
            'near_ema' => $this->abs($close->minus($ema))->compareTo($atrOf($p['pullback_max_atr_from_ema'])) <= 0,
            'above_fast_sma' => $close->compareTo($smaFast) > 0,
        ];
        $result['checks'] = $checks;
        $base = $checks['uptrend'] && $checks['relative_strength'] && $checks['volatility'];
        if ($base && $checks['tight_range']) {
            [$setup, $trigger] = ['breakout', $high];
        } elseif ($base && $checks['near_ema'] && $checks['above_fast_sma']) {
            [$setup, $trigger] = ['pullback', Decimal::from($bars[array_key_last($bars)][2])];
        } else {
            [$setup, $trigger] = ['none', null];
        }
        $result['setup'] = $setup;
        $result['status'] = 'complete';
        $result['reason'] = match (true) {
            $setup !== 'none' => $setup.'_setup',
            ! $checks['uptrend'] => 'not_in_uptrend',
            ! $checks['relative_strength'] => 'relative_strength_weak',
            ! $checks['volatility'] => 'volatility_too_high',
            default => 'no_setup_pattern',
        };
        if ($trigger !== null) {
            // Rounded toward less risk: a lower maximum entry and a higher reference stop.
            $result['levels'] = ['trigger' => (string) $trigger,
                'max_entry' => (string) $trigger->plus($atrOf($p['max_chase_atr']))->rounded($levelScale, RoundingMode::DOWN),
                'initial_stop' => (string) $trigger->minus($atrOf($p['initial_stop_atr']))->rounded($levelScale, RoundingMode::UP)];
        }
        $earningsBlocker = match ($result['earnings']['status']) {
            'pass' => null,
            'blocked' => 'earnings_blackout',
            default => 'earnings_date_unknown',
        };
        $result['entry_blockers'] = array_values(array_filter([$filter ? null : 'market_filter_off', $earningsBlocker]));

        return $result;
    }

    private function sma(array $values, int $n, int $scale = 8): Decimal
    {
        return $this->sum(array_slice($values, -$n))->dividedBy(Decimal::from((string) $n), $scale);
    }

    /** Seeded with the first window's simple average; every step rounds to 12 places so results are reproducible. */
    private function ema(array $values, int $n, int $scale = 8): Decimal
    {
        $internalScale = $scale === 8 ? 12 : $scale + 6;
        $k = Decimal::from('2')->dividedBy(Decimal::from((string) ($n + 1)), $internalScale);
        $ema = $this->sum(array_slice($values, 0, $n))->dividedBy(Decimal::from((string) $n), $internalScale);
        foreach (array_slice($values, $n) as $value) {
            $ema = $ema->plus($k->multipliedBy($value->minus($ema)))->rounded($internalScale, RoundingMode::HALF_EVEN);
        }

        return $ema->rounded($scale, RoundingMode::HALF_EVEN);
    }

    /** Simple mean of the last n true ranges. */
    private function atr(array $bars, int $n, int $scale = 8): Decimal
    {
        $ranges = [];
        for ($i = count($bars) - $n; $i < count($bars); $i++) {
            [$h, $l, $previous] = [Decimal::from($bars[$i][2]), Decimal::from($bars[$i][3]), Decimal::from($bars[$i - 1][4])];
            $range = $h->minus($l);
            foreach ([$this->abs($h->minus($previous)), $this->abs($l->minus($previous))] as $candidate) {
                $range = $candidate->compareTo($range) > 0 ? $candidate : $range;
            }
            $ranges[] = $range;
        }

        return $this->sum($ranges)->dividedBy(Decimal::from((string) $n), $scale);
    }

    private function change(array $values, int $n, int $scale = 8): Decimal
    {
        $last = $values[array_key_last($values)];

        return $last->dividedBy($values[count($values) - 1 - $n], $scale)->minus(Decimal::from('1'));
    }

    private function extreme(array $bars, int $index, int $direction): Decimal
    {
        $best = Decimal::from($bars[0][$index]);
        foreach ($bars as $bar) {
            $value = Decimal::from($bar[$index]);
            if ($value->compareTo($best) * $direction > 0) {
                $best = $value;
            }
        }

        return $best;
    }

    private function sum(array $values): Decimal
    {
        return array_reduce($values, fn (Decimal $carry, Decimal $value) => $carry->plus($value), Decimal::from('0'));
    }

    private function abs(Decimal $value): Decimal
    {
        return $value->compareTo(Decimal::from('0')) < 0 ? Decimal::from('0')->minus($value) : $value;
    }
}
