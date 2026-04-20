<?php

namespace App\Data\Trading;

final readonly class PolicyEvaluation
{
    /**
     * @param array<string, bool> $checks
     * @param array<string, string> $messages
     * @param array<string, mixed> $context
     */
    public function __construct(
        public bool $passed,
        public bool $requiresHumanApproval = true,
        public array $checks = [],
        public array $messages = [],
        public array $context = [],
    ) {
    }
}
