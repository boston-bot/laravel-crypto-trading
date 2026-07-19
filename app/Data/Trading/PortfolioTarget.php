<?php

namespace App\Data\Trading;

final readonly class PortfolioTarget
{
    public function __construct(public float $cashWeight, public array $positions, public string $targetHash) {}

    public static function fromArray(array $value): self
    {
        return new self((float) $value['cash_weight'], (array) $value['positions'], (string) $value['target_hash']);
    }

    public function toArray(): array
    {
        return ['cash_weight' => $this->cashWeight, 'positions' => $this->positions, 'target_hash' => $this->targetHash];
    }
}
