<?php

namespace App\Data\Research;

use App\Models\EngineResult;
use Carbon\CarbonImmutable;

final readonly class EvaluationResult
{
    /** @param array<int, array<string, mixed>> $proposals */
    public function __construct(
        public string $engineVersion,
        public string $schemaVersion,
        public string $jobId,
        public CarbonImmutable $asOf,
        public ?CarbonImmutable $validUntil,
        public string $manifestHash,
        public array $proposals,
        public array $diagnostics = [],
    ) {}

    public static function fromModel(EngineResult $result): self
    {
        $payload = (array) $result->payload_json;

        return new self(
            engineVersion: $result->engine_version,
            schemaVersion: $result->schema_version,
            jobId: $result->engine_job_id,
            asOf: CarbonImmutable::instance($result->as_of),
            validUntil: $result->valid_until ? CarbonImmutable::instance($result->valid_until) : null,
            manifestHash: $result->manifest_hash,
            proposals: (array) ($payload['proposals'] ?? []),
            diagnostics: (array) ($payload['diagnostics'] ?? []),
        );
    }
}
