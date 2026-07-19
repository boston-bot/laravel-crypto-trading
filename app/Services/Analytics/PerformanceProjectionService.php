<?php

namespace App\Services\Analytics;

use App\Data\Analytics\PerformancePeriod;
use App\Models\PaperPortfolioSnapshot;
use App\Models\PaperSession;
use Carbon\CarbonInterface;

class PerformanceProjectionService
{
    /** @return array{performance: array<string, int|string|null>, portfolio_summary: array<string, mixed>} */
    public function paper(
        ?PaperSession $session,
        CarbonInterface $periodStart,
        CarbonInterface $asOf,
        string $interval = '4h',
        ?float $benchmarkReturnPct = null,
    ): array {
        $intervalSeconds = $this->intervalSeconds($interval);
        $startEquity = null;
        $endEquity = null;
        $lastValuationAt = null;

        if ($session !== null && $session->started_at?->lte($periodStart)) {
            $startSnapshot = PaperPortfolioSnapshot::query()
                ->where('paper_session_id', $session->id)
                ->where('snapshot_time', '<=', $periodStart)
                ->latest('snapshot_time')
                ->first();
            $endSnapshot = PaperPortfolioSnapshot::query()
                ->where('paper_session_id', $session->id)
                ->where('snapshot_time', '<=', $asOf)
                ->latest('snapshot_time')
                ->first();

            if ($startSnapshot !== null && $periodStart->diffInSeconds($startSnapshot->snapshot_time) <= $intervalSeconds) {
                $startEquity = (float) $startSnapshot->equity;
            } elseif ($session->started_at->equalTo($periodStart)) {
                $startEquity = (float) $session->opening_cash;
            }

            if ($endSnapshot !== null) {
                $endEquity = (float) $endSnapshot->equity;
                $lastValuationAt = $endSnapshot->snapshot_time;
            }
        }

        $measured = $startEquity !== null && $startEquity > 0 && $endEquity !== null;
        $freshnessSeconds = (int) ($lastValuationAt === null
            ? $periodStart->diffInSeconds($asOf)
            : $lastValuationAt->diffInSeconds($asOf));
        $freshnessState = $lastValuationAt !== null && $freshnessSeconds <= $intervalSeconds ? 'fresh' : 'stale';
        $completenessState = $measured && $freshnessState === 'fresh' ? 'complete' : 'incomplete';
        $measurementState = $measured ? 'measured' : 'not_measured';
        $strategyReturnPct = $measured ? (($endEquity - $startEquity) / $startEquity) * 100 : null;
        $relativeReturnPct = $strategyReturnPct !== null && $benchmarkReturnPct !== null
            ? $strategyReturnPct - $benchmarkReturnPct
            : null;

        $period = new PerformancePeriod(
            periodStart: $periodStart->toIso8601String(),
            asOf: $asOf->toIso8601String(),
            interval: $interval,
            freshnessSeconds: $freshnessSeconds,
            freshnessState: $freshnessState,
            completenessState: $completenessState,
            measurementState: $measurementState,
            strategyVersionId: $session?->strategy_version_id,
            universeVersionId: $session?->universe_version_id,
            valuationPolicy: 'paper-mark-to-market-v1',
            costPolicy: 'paper-ledger-net-of-recorded-costs-v1',
            benchmarkPolicy: 'coinbase-buy-and-hold-price-return-v1',
        );

        return [
            'performance' => $period->toArray(),
            'portfolio_summary' => [
                'strategy_return_pct' => $this->round($strategyReturnPct),
                'benchmark_return_pct' => $this->round($benchmarkReturnPct),
                'relative_benchmark_return_pct' => $this->round($relativeReturnPct),
                'formula' => 'strategy_return_pct - benchmark_return_pct',
                'source_values' => [
                    'start_equity' => $this->round($startEquity, 8),
                    'end_equity' => $this->round($endEquity, 8),
                    'strategy_return_pct' => $this->round($strategyReturnPct),
                    'benchmark_return_pct' => $this->round($benchmarkReturnPct),
                ],
            ],
        ];
    }

    private function intervalSeconds(string $interval): int
    {
        return match ($interval) {
            '1h' => 3600,
            '1d' => 86400,
            default => 14400,
        };
    }

    private function round(?float $value, int $precision = 4): ?float
    {
        return $value === null ? null : round($value, $precision);
    }
}
