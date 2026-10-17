<?php

namespace App\Services\Broker\Agentic;

use DateTimeImmutable;
use JsonSerializable;

final readonly class ReadResult implements JsonSerializable
{
    /** @param list<BrokerRecord> $records */
    public function __construct(
        public string $source,
        public DateTimeImmutable $retrievedAt,
        public array $records,
        public bool $complete,
        public ?string $continuation = null,
        public ?ReadError $incompleteReason = null,
        public int $pages = 1,
        public string $coverage = 'configured_operation',
    ) {}

    public function jsonSerialize(): array
    {
        return ['source' => $this->source, 'retrieved_at' => $this->retrievedAt->format(DATE_ATOM), 'records' => $this->records,
            'completeness' => $this->complete ? 'complete' : 'partial', 'continuation' => $this->continuation,
            'incomplete_reason' => $this->incompleteReason?->value, 'pages' => $this->pages, 'coverage' => $this->coverage];
    }
}
