<?php

namespace App\Data\Analytics;

final readonly class PerformancePeriod
{
    public function __construct(
        public string $periodStart,
        public string $asOf,
        public string $interval,
        public int $freshnessSeconds,
        public string $freshnessState,
        public string $completenessState,
        public string $measurementState,
        public ?int $strategyVersionId,
        public ?int $universeVersionId,
        public string $valuationPolicy,
        public string $costPolicy,
        public string $benchmarkPolicy,
    ) {}

    /** @return array<string, int|string|null> */
    public function toArray(): array
    {
        return [
            'period_start' => $this->periodStart,
            'as_of' => $this->asOf,
            'interval' => $this->interval,
            'freshness_seconds' => $this->freshnessSeconds,
            'freshness_state' => $this->freshnessState,
            'completeness_state' => $this->completenessState,
            'measurement_state' => $this->measurementState,
            'strategy_version_id' => $this->strategyVersionId,
            'universe_version_id' => $this->universeVersionId,
            'valuation_policy' => $this->valuationPolicy,
            'cost_policy' => $this->costPolicy,
            'benchmark_policy' => $this->benchmarkPolicy,
        ];
    }
}
