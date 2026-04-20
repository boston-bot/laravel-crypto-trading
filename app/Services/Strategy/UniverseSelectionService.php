<?php

namespace App\Services\Strategy;

use App\Enums\BrokerType;
use App\Models\Asset;
use App\Models\MarketCandle;
use Illuminate\Database\Eloquent\Collection;

class UniverseSelectionService
{
    public function eligibleAssets(string $timeframe = '1d'): Collection
    {
        $allowed = array_map('strtoupper', (array) config('trading.allowed_assets', []));
        $broker = BrokerType::default();

        $query = Asset::query()
            ->where('broker', $broker->value)
            ->where('is_enabled', true)
            ->where('is_tradable', true)
            ->orderBy('symbol');

        if ($allowed !== []) {
            $query->whereIn('symbol', $allowed);
        }

        /** @var Collection<int, Asset> $assets */
        $assets = $query->get();

        if (! (bool) config('trading.universe.require_history', false)) {
            return $assets;
        }

        $requiredCandles = (int) config('trading.universe.minimum_candle_history', 120);

        return $assets
            ->filter(function (Asset $asset) use ($timeframe, $requiredCandles): bool {
                $count = MarketCandle::query()
                    ->where('asset_id', $asset->id)
                    ->where('timeframe', $timeframe)
                    ->count();

                return $count >= $requiredCandles;
            })
            ->values();
    }
}
