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

/**
 * Measured cadence of one regular session from stored records only: collection slots and spans, quote age when
 * retrieved and when a signal used it, per-symbol refresh intervals, and strategy-cycle completeness and lag.
 * Read-only; durations are milliseconds. A session the calendar does not know is reported as unknown.
 */
final class CadenceReport
{
    public const SLOT_SECONDS = 300;

    public function session(McpConnection $connection, string $date, int $quoteMaxAge = 20): array
    {
        $session = MarketSession::where('exchange', 'XNYS')->where('session_date', $date)->first();
        if (! $session || ! in_array($session->status, ['regular', 'early_close'], true)) {
            return ['date' => $date, 'status' => 'unavailable', 'reason' => $session ? 'no_regular_session' : 'session_unknown'];
        }
        [$open, $close] = [CarbonImmutable::instance($session->opens_at), CarbonImmutable::instance($session->closes_at)];
        $scope = AccountScope::key($connection);
        $range = [$open->utc()->format('Y-m-d H:i:s.u'), $close->utc()->format('Y-m-d H:i:s.u')];
        $inputs = MarketInput::where('mcp_connection_id', $connection->id)->where('scope', $scope)->where('origin', 'broker_read')
            ->whereBetween('recorded_at', $range)->orderBy('recorded_at')->get(['symbol', 'source_at', 'retrieved_at', 'recorded_at']);
        $expected = intdiv($close->getTimestamp() - $open->getTimestamp(), self::SLOT_SECONDS);
        $slots = [];
        $bySymbol = [];
        $retrievalAges = [];
        foreach ($inputs as $input) {
            $slot = intdiv($input->recorded_at->getTimestamp() - $open->getTimestamp(), self::SLOT_SECONDS);
            $slots[$slot][] = $input;
            $bySymbol[$input->symbol][] = $input->recorded_at;
            if ($input->source_at) {
                $retrievalAges[] = $this->ms($input->source_at, $input->retrieved_at);
            }
        }
        $spans = array_map(fn ($rows) => $this->ms($rows[0]->recorded_at, $rows[array_key_last($rows)]->recorded_at), $slots);
        $intervals = [];
        foreach ($bySymbol as $times) {
            for ($i = 1; $i < count($times); $i++) {
                $intervals[] = $this->ms($times[$i - 1], $times[$i]);
            }
        }
        $cycles = StrategyCycle::where('mcp_connection_id', $connection->id)->where('scope', $scope)->whereBetween('cycle_at', $range)->get(['id', 'cycle_at', 'recorded_at']);
        // Signals carry no scope column; they are scoped through their cycle.
        $signals = StrategySignal::where('mcp_connection_id', $connection->id)->whereIn('cycle_id', $cycles->pluck('id'))->get(['status', 'reason', 'cutoff', 'payload']);
        $evaluationAges = [];
        foreach ($signals as $signal) {
            $source = $signal->payload['evidence']['market_input_times']['source_at'] ?? null;
            if ($source !== null) {
                $evaluationAges[] = $this->ms(CarbonImmutable::parse($source), CarbonImmutable::instance($signal->cutoff));
            }
        }
        $reasons = $signals->countBy('reason')->sortDesc()->take(8)->all();

        return ['date' => $date, 'status' => 'measured', 'session' => ['opens_at' => $open->toIso8601String(), 'closes_at' => $close->toIso8601String(), 'expected_slots' => $expected],
            'collection' => ['observations' => $inputs->count(), 'symbols' => count($bySymbol), 'slots_with_reads' => count($slots),
                'slot_coverage_pct' => $expected === 0 ? null : round(count($slots) * 100 / $expected, 1),
                'symbols_per_slot' => $this->summary(array_map('count', $slots)), 'slot_span_ms' => $this->summary(array_values($spans)),
                'quote_age_at_retrieval_ms' => $this->summary($retrievalAges), 'refresh_interval_ms' => $this->summary($intervals)],
            'strategy' => ['cycles' => $cycles->count(), 'expected_cycles' => $expected,
                'cycle_lag_ms' => $this->summary($cycles->map(fn ($c) => $this->ms($c->cycle_at, $c->recorded_at))->all()),
                'signals' => $signals->count(), 'signal_status' => $signals->countBy('status')->all(), 'top_reasons' => $reasons,
                'quote_age_at_evaluation_ms' => $this->summary($evaluationAges),
                'within_quote_max_age_pct' => $evaluationAges === [] ? null : round(count(array_filter($evaluationAges, fn ($ms) => $ms <= $quoteMaxAge * 1000)) * 100 / count($evaluationAges), 1),
                'quote_max_age_seconds' => $quoteMaxAge]];
    }

    private function ms($from, $to): int
    {
        return (int) round((CarbonImmutable::instance($to)->getTimestampMs() - CarbonImmutable::instance($from)->getTimestampMs()));
    }

    /** @return array{count: int, p50: ?int, p95: ?int, max: ?int} */
    private function summary(array $values): array
    {
        sort($values);
        // Nearest-rank percentile: the value at position ceil(q * n) in sorted order.
        $at = fn (float $q) => $values === [] ? null : $values[max(0, (int) ceil($q * count($values)) - 1)];

        return ['count' => count($values), 'p50' => $at(0.5), 'p95' => $at(0.95), 'max' => $values === [] ? null : $values[array_key_last($values)]];
    }
}
