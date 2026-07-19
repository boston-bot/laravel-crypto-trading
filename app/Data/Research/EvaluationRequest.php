<?php

namespace App\Data\Research;

use Carbon\CarbonImmutable;

final readonly class EvaluationRequest
{
    /**
     * @param  array<int, array{id: int, symbol: string}>  $assets
     */
    public function __construct(
        public int $brokerAccountId,
        public int $strategyRunId,
        public CarbonImmutable $asOf,
        public array $assets,
        public ?int $strategyVersionId = null,
        public ?int $universeVersionId = null,
        public ?int $manifestId = null,
        public string $schemaVersion = '1.0',
        public string $mode = 'paper',
        public string $evaluationKind = 'trading',
        public ?CarbonImmutable $logicalBarClose = null,
        public ?string $pipelineCycleId = null,
        public ?CarbonImmutable $evidenceCutoff = null,
        public array $portfolioContext = [],
        public ?string $portfolioContextHash = null,
        public array $strategyDefinition = [],
        public array $universeDefinition = [],
    ) {}

    public function idempotencyKey(): string
    {
        $assetIds = array_column($this->assets, 'id');
        sort($assetIds);

        return hash('sha256', implode('|', [
            'evaluate',
            $this->brokerAccountId,
            implode(',', $assetIds),
            $this->strategyVersionId ?? 'legacy',
            $this->universeVersionId ?? 'default',
            $this->mode,
            $this->evaluationKind,
            ($this->logicalBarClose ?? $this->asOf)->utc()->format('Y-m-d\TH:i:s\Z'),
            $this->evaluationKind === 'diagnostic' ? $this->asOf->utc()->format('Y-m-d\TH:i:s\Z') : 'trading',
            $this->evaluationKind === 'diagnostic' ? ($this->manifestId ?? 'unmanifested') : 'logical-bar',
        ]));
    }

    /** @return array<string, mixed> */
    public function toPayload(): array
    {
        return [
            'broker_account_id' => $this->brokerAccountId,
            'strategy_run_id' => $this->strategyRunId,
            'assets' => $this->assets,
            'timeframes' => ['1h', '4h', '1d'],
            'point_in_time' => true,
            'sentiment_mode' => 'ablation_only',
            'mode' => $this->mode,
            'evaluation_kind' => $this->evaluationKind,
            'logical_bar_close' => ($this->logicalBarClose ?? $this->asOf)->utc()->toIso8601String(),
            'evidence_cutoff' => ($this->evidenceCutoff ?? $this->asOf)->utc()->toIso8601String(),
            'proposal_ttl_minutes' => (int) config('research.engine.proposal_ttl_minutes', 30),
            'max_signal_age_minutes' => (int) config('research.engine.max_signal_age_minutes', 240),
            'pipeline_cycle_id' => $this->pipelineCycleId,
            'portfolio_context' => $this->portfolioContext,
            'portfolio_context_hash' => $this->portfolioContextHash,
            'strategy_definition' => $this->strategyDefinition,
            'universe_definition' => $this->universeDefinition,
        ];
    }
}
