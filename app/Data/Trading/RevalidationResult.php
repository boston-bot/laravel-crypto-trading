<?php

namespace App\Data\Trading;

final readonly class RevalidationResult
{
    /** @param array<int, string> $reasons */
    public function __construct(
        public bool $passed,
        public array $reasons,
        public array $context = [],
    ) {}
}
