<?php

namespace App\Services\Research;

final readonly class ModelResponse
{
    /** @param 'completed'|'refused'|'incomplete' $status */
    public function __construct(
        public string $status,
        public ?string $text,
        public ?int $inputTokens,
        public ?int $outputTokens,
        public string $model,
    ) {}
}
