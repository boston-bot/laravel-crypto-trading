<?php

namespace App\Data\Trading;

final readonly class RiskEvaluation
{
    /**
     * @param array<int, string> $violations
     * @param array<string, mixed> $context
     */
    public function __construct(
        public bool $passed,
        public array $violations = [],
        public array $context = [],
    ) {
    }
}

