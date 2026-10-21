<?php

declare(strict_types=1);

namespace App\Services\PaperTrading;

use App\Models\McpConnection;
use App\Models\Monitoring\MarketSession;
use App\Models\PaperTrading\MarketInput;
use App\Models\PaperTrading\StrategyCycle;
use App\Models\PaperTrading\StrategySignal;
use App\Services\Monitoring\AccountScope;
use Carbon\CarbonImmutable;

/** Read-only session progress and observed quote cadence. Quote quality is not entry or execution permission. */
final class CadenceReport
{
    public const VERSION = 'stored_cadence_v2';

    public const SLOT_SECONDS = 300;

    public function session(McpConnection $connection, string $date, int $quoteMaxAge = 20): array
    {
        $asOf = CarbonImmutable::now();
        $session = MarketSession::where('exchange', 'XNYS')->where('session_date', $date)->first();
        if (! $session || ! in_array($session->status, ['regular', 'early_close'], true)) {
            return ['version' => self::VERSION, 'date' => $date, 'status' => 'unavailable', 'reason' => $session ? 'no_regular_session' : 'session_unknown'];
        }
        [$open, $close] = [CarbonImmutable::instance($session->opens_at), CarbonImmutable::instance($session->closes_at)];
        $phase = $asOf->lt($open) ? 'pending' : ($asOf->lt($close) ? 'in_progress' : 'complete');
        $expected = intdiv($close->getTimestamp() - $open->getTimestamp(), self::SLOT_SECONDS);
        $elapsed = min($expected, max(0, intdiv($asOf->getTimestamp() - $open->getTimestamp(), self::SLOT_SECONDS)));
        $scope = AccountScope::key($connection);
        $stamp = fn (CarbonImmutable $time) => $time->utc()->format('Y-m-d H:i:s.u');
        $inputs = MarketInput::where('mcp_connection_id', $connection->id)->where('scope', $scope)->where('origin', 'broker_read')
            ->where('recorded_at', '>=', $stamp($open))->where('recorded_at', '<', $stamp($close))
            ->where('recorded_at', '<=', $stamp($asOf))->where('retrieved_at', '<=', $stamp($asOf))->orderBy('recorded_at')->orderBy('id')->cursor();
        $slots = $slotSymbols = $bySymbol = $usableBySymbol = $usableSlots = $retrievalAges = [];
        $observations = $usable = $unknownSource = $invalidTimes = 0;
        foreach ($inputs as $input) {
            $observations++;
            $slot = intdiv($input->recorded_at->getTimestamp() - $open->getTimestamp(), self::SLOT_SECONDS);
            $slots[$slot][] = $input->recorded_at;
            $slotSymbols[$slot][$input->symbol] = true;
            $bySymbol[$input->symbol][] = $input->recorded_at;
            if ($input->retrieved_at->gt($input->recorded_at) || $input->source_at?->gt($input->retrieved_at)) {
                $invalidTimes++;
            } elseif (! $input->source_at) {
                $unknownSource++;
            } else {
                $retrievalAges[] = $this->ms($input->source_at, $input->retrieved_at);
            }
            if (StoredStrategyQuote::read($input, $connection, CarbonImmutable::instance($input->recorded_at), $quoteMaxAge) !== null) {
                $usable++;
                $usableSlots[$slot] = true;
                $usableBySymbol[$input->symbol][] = $input->recorded_at;
            }
        }
        $spans = array_map(fn ($times) => $this->ms($times[0], $times[array_key_last($times)]), $slots);
        $elapsedReads = count(array_filter(array_keys($slots), fn ($slot) => $slot < $elapsed));
        $elapsedUsable = count(array_filter(array_keys($usableSlots), fn ($slot) => $slot < $elapsed));
        $cycles = StrategyCycle::where('mcp_connection_id', $connection->id)->where('scope', $scope)
            ->where('cycle_at', '>=', $stamp($open))->where('cycle_at', '<', $stamp($close))
            ->where('cycle_at', '<=', $stamp($asOf))->where('recorded_at', '<=', $stamp($asOf))
            ->get(['id', 'cycle_at', 'recorded_at']);
        // Signals carry no scope column; they are scoped through their cycle and local availability time.
        $signals = StrategySignal::where('mcp_connection_id', $connection->id)->whereIn('cycle_id', $cycles->pluck('id'))
            ->where('recorded_at', '<=', $stamp($asOf))->where('cutoff', '<=', $stamp($asOf))->get(['status', 'reason', 'cutoff', 'payload']);
        $evaluationAges = [];
        $unknownEvaluationTime = $invalidEvaluationTime = 0;
        foreach ($signals as $signal) {
            $source = $signal->payload['evidence']['market_input_times']['source_at'] ?? null;
            if ($source === null) {
                $unknownEvaluationTime++;

                continue;
            }
            try {
                if (! is_string($source) || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/D', $source)) {
                    throw new \InvalidArgumentException('Unknown source timestamp.');
                }
                $time = CarbonImmutable::parse($source);
                if ($time->gt($signal->cutoff)) {
                    throw new \InvalidArgumentException('Source time is after the evaluation.');
                }
                $evaluationAges[] = $this->ms($time, $signal->cutoff);
            } catch (\InvalidArgumentException) {
                $invalidEvaluationTime++;
            }
        }
        $reasons = $signals->countBy('reason')->sortDesc()->take(8)->all();

        return ['version' => self::VERSION, 'date' => $date, 'status' => 'measured', 'as_of' => $asOf->toISOString(),
            'session' => ['opens_at' => $open->toIso8601String(), 'closes_at' => $close->toIso8601String(), 'phase' => $phase,
                'expected_slots' => $expected, 'elapsed_slots' => $elapsed, 'remaining_slots' => $expected - $elapsed],
            'collection' => ['observations' => $observations, 'symbols' => count($bySymbol), 'slots_with_reads' => count($slots),
                // Retain the full-session denominator for compatibility; elapsed coverage excludes the open slot.
                'slot_coverage_pct' => $expected === 0 ? null : round(count($slots) * 100 / $expected, 1),
                'elapsed_slots_with_reads' => $elapsedReads, 'elapsed_slot_coverage_pct' => $elapsed === 0 ? null : round($elapsedReads * 100 / $elapsed, 1),
                'symbols_per_slot' => $this->summary(array_map('count', $slotSymbols)), 'observations_per_slot' => $this->summary(array_map('count', $slots)),
                'slot_span_ms' => $this->summary(array_values($spans)), 'quote_age_at_retrieval_ms' => $this->summary($retrievalAges),
                'unknown_source_time_observations' => $unknownSource, 'invalid_time_observations' => $invalidTimes,
                'refresh_interval_ms' => $this->summary($this->intervals($bySymbol)),
                'signal_quote_quality' => ['assessment' => 'at_recording_under_current_authorization', 'quote_max_age_seconds' => $quoteMaxAge,
                    'usable_observations' => $usable, 'unusable_observations' => $observations - $usable, 'symbols_with_usable_quotes' => count($usableBySymbol),
                    'slots_with_usable_quotes' => count($usableSlots), 'elapsed_slots_with_usable_quotes' => $elapsedUsable,
                    'elapsed_slot_coverage_pct' => $elapsed === 0 ? null : round($elapsedUsable * 100 / $elapsed, 1),
                    'refresh_interval_ms' => $this->summary($this->intervals($usableBySymbol))]],
            'strategy' => ['cycles' => $cycles->count(), 'expected_cycles' => $expected, 'elapsed_expected_cycles' => $elapsed,
                'elapsed_cycles' => $cycles->filter(fn ($cycle) => $cycle->cycle_at->lt($open->addSeconds($elapsed * self::SLOT_SECONDS)))->count(),
                'cycle_lag_ms' => $this->summary($cycles->map(fn ($c) => $this->ms($c->cycle_at, $c->recorded_at))->all()),
                'signals' => $signals->count(), 'signal_status' => $signals->countBy('status')->all(), 'top_reasons' => $reasons,
                'quote_age_at_evaluation_ms' => $this->summary($evaluationAges),
                'unknown_quote_time_signals' => $unknownEvaluationTime, 'invalid_quote_time_signals' => $invalidEvaluationTime,
                'within_quote_max_age_pct' => $evaluationAges === [] ? null : round(count(array_filter($evaluationAges, fn ($ms) => $ms <= $quoteMaxAge * 1000)) * 100 / count($evaluationAges), 1),
                'quote_max_age_seconds' => $quoteMaxAge]];
    }

    private function intervals(array $bySymbol): array
    {
        $intervals = [];
        foreach ($bySymbol as $times) {
            for ($i = 1; $i < count($times); $i++) {
                $intervals[] = $this->ms($times[$i - 1], $times[$i]);
            }
        }

        return $intervals;
    }

    private function ms($from, $to): int
    {
        return (int) round((CarbonImmutable::instance($to)->getTimestampMs() - CarbonImmutable::instance($from)->getTimestampMs()));
    }

    /** @return array{count: int, p50: ?int, p95: ?int, max: ?int} */
    private function summary(array $values): array
    {
        sort($values);
        $at = fn (float $q) => $values === [] ? null : $values[max(0, (int) ceil($q * count($values)) - 1)];

        return ['count' => count($values), 'p50' => $at(0.5), 'p95' => $at(0.95), 'max' => $values === [] ? null : $values[array_key_last($values)]];
    }
}
