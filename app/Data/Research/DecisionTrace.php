<?php

namespace App\Data\Research;

use InvalidArgumentException;

final readonly class DecisionTrace
{
    public function __construct(
        public string $strategyFamily,
        public string $strategyDefinitionVersion,
        public int $rank,
        public array $portfolioState,
        public array $ruleChecklist,
        public array $factorContributions,
        public float $grossEdgeBps,
        public float $costEstimateBps,
        public float $netEdgeBps,
        public string $primaryExplanation,
        public array $reasonCodes,
        public string $counterfactual,
        public string $evidenceHash,
        public string $parameterHash,
    ) {
        foreach ([$grossEdgeBps, $costEstimateBps, $netEdgeBps] as $number) {
            if (! is_finite($number)) {
                throw new InvalidArgumentException('Decision trace numbers must be finite.');
            }
        }
        foreach ([$evidenceHash, $parameterHash] as $hash) {
            if (! preg_match('/^[a-f0-9]{64}$/', $hash)) {
                throw new InvalidArgumentException('Decision trace hashes must be lowercase SHA-256 values.');
            }
        }
    }

    public static function fromArray(array $value): self
    {
        $required = ['strategy_family', 'strategy_definition_version', 'rank', 'portfolio_state', 'rule_checklist', 'factor_contributions', 'gross_edge_bps', 'cost_estimate_bps', 'net_edge_bps', 'primary_explanation', 'reason_codes', 'counterfactual', 'evidence_hash', 'parameter_hash'];
        foreach ($required as $field) {
            if (! array_key_exists($field, $value)) {
                throw new InvalidArgumentException("Decision trace is missing {$field}.");
            }
        }

        return new self((string) $value['strategy_family'], (string) $value['strategy_definition_version'], (int) $value['rank'], (array) $value['portfolio_state'], (array) $value['rule_checklist'], (array) $value['factor_contributions'], (float) $value['gross_edge_bps'], (float) $value['cost_estimate_bps'], (float) $value['net_edge_bps'], (string) $value['primary_explanation'], array_values((array) $value['reason_codes']), (string) $value['counterfactual'], (string) $value['evidence_hash'], (string) $value['parameter_hash']);
    }

    public function toArray(): array
    {
        return ['strategy_family' => $this->strategyFamily, 'strategy_definition_version' => $this->strategyDefinitionVersion, 'rank' => $this->rank, 'portfolio_state' => $this->portfolioState, 'rule_checklist' => $this->ruleChecklist, 'factor_contributions' => $this->factorContributions, 'gross_edge_bps' => $this->grossEdgeBps, 'cost_estimate_bps' => $this->costEstimateBps, 'net_edge_bps' => $this->netEdgeBps, 'primary_explanation' => $this->primaryExplanation, 'reason_codes' => $this->reasonCodes, 'counterfactual' => $this->counterfactual, 'evidence_hash' => $this->evidenceHash, 'parameter_hash' => $this->parameterHash];
    }

    public function canonicalHash(): string
    {
        $value = $this->toArray();
        self::sortRecursively($value);

        return hash('sha256', json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private static function sortRecursively(array &$value): void
    {
        if (! array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as &$child) {
            if (is_array($child)) {
                self::sortRecursively($child);
            }
        }
    }
}
