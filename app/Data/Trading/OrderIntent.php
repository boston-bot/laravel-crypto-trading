<?php

namespace App\Data\Trading;

use Carbon\CarbonImmutable;

final readonly class OrderIntent
{
    public function __construct(public string $venue, public string $productId, public string $side, public float $quantity, public float $referencePrice, public CarbonImmutable $earliestExecutionAt, public string $idempotencyKey, public string $intentHash, public array $payload) {}

    public static function fromArray(array $value): self
    {
        return new self((string) $value['venue'], (string) $value['product_id'], (string) $value['side'], (float) $value['normalized_base_quantity'], (float) $value['reference_price'], CarbonImmutable::parse((string) $value['earliest_execution_at'])->utc(), (string) $value['idempotency_key'], (string) $value['intent_hash'], $value);
    }
}
