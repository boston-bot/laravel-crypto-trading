<?php

namespace App\Data\MarketData;

use Carbon\CarbonImmutable;

final readonly class CommonMarketBar
{
    /**
     * @param  array<int, int>  $assetIds
     * @param  array<int, int>  $candleIdsByAsset
     */
    public function __construct(
        public CarbonImmutable $logicalBarClose,
        public CarbonImmutable $evidenceCutoff,
        public array $assetIds,
        public array $candleIdsByAsset,
    ) {}
}
