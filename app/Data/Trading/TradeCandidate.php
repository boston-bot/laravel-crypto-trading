<?php

namespace App\Data\Trading;

use App\Enums\OrderSide;

final readonly class TradeCandidate
{
    /**
     * @param  array<string, mixed>  $marketContext
     * @param  array<string, mixed>  $signalContext
     */
    public function __construct(
        public int $assetId,
        public string $symbol,
        public OrderSide $side,
        public float $quantity,
        public float $notionalUsd,
        public float $score,
        public float $confidence,
        public array $marketContext = [],
        public array $signalContext = [],
    ) {}
}
