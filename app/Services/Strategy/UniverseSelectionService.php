<?php

namespace App\Services\Strategy;

use App\Enums\BrokerType;
use App\Models\Asset;
use App\Models\MarketCandle;
use App\Models\UniverseMembership;
use App\Models\UniverseVersion;
use App\Models\VenueProduct;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class UniverseSelectionService
{
    public function buildVersionAt(CarbonInterface $asOf, string $name = 'coinbase-usd'): UniverseVersion
    {
        $at = CarbonImmutable::instance($asOf)->utc();
        $products = VenueProduct::query()->with('asset')
            ->where('venue', BrokerType::default()->value)->where('quote_asset', 'USD')
            ->where('valid_from', '<=', $at)
            ->where(fn ($query) => $query->whereNull('valid_to')->orWhere('valid_to', '>', $at))
            ->whereIn('status', ['online', 'limit_only'])->orderBy('product_id')->get();
        $evidence = $products->map(fn (VenueProduct $product): array => [
            'asset_id' => $product->asset_id, 'product_id' => $product->product_id, 'status' => $product->status,
            'valid_from' => $product->valid_from->utc()->toIso8601String(), 'valid_to' => $product->valid_to?->utc()->toIso8601String(),
            'metadata' => $product->metadata_json,
        ])->all();
        $hash = hash('sha256', json_encode($evidence, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return DB::transaction(function () use ($name, $at, $products, $evidence, $hash): UniverseVersion {
            $version = UniverseVersion::query()->firstOrCreate(['content_hash' => $hash], [
                'name' => $name, 'version' => $at->format('Ymd\THis\Z'), 'status' => 'active',
                'symbols_json' => $products->pluck('base_asset')->values()->all(),
                'rules_json' => ['venue' => BrokerType::default()->value, 'quote_asset' => 'USD', 'as_of' => $at->toIso8601String()],
                'activated_at' => $at,
            ]);
            if ($version->wasRecentlyCreated) {
                foreach ($products as $index => $product) {
                    if ($product->asset_id === null) { continue; }
                    $asset = $product->asset;
                    $rowEvidence = $evidence[$index];
                    UniverseMembership::query()->create([
                        'universe_version_id' => $version->id, 'asset_id' => $product->asset_id, 'venue_product_id' => $product->id,
                        'venue' => $product->venue, 'product_id' => $product->product_id, 'quote_asset' => $product->quote_asset,
                        'valid_from' => $product->valid_from, 'valid_to' => $product->valid_to,
                        'listed_at' => data_get($product->metadata_json, 'listed_at', $product->valid_from), 'delisted_at' => data_get($product->metadata_json, 'delisted_at'),
                        'trading_state' => $product->status, 'price_precision' => $asset?->price_precision,
                        'quantity_precision' => $asset?->quantity_precision, 'minimum_notional' => $asset?->min_order_notional,
                        'evidence_json' => $rowEvidence, 'evidence_hash' => hash('sha256', json_encode($rowEvidence, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
                    ]);
                }
            }
            return $version->load('memberships.asset');
        });
    }

    public function assetsForVersionAt(UniverseVersion $version, CarbonInterface $at): Collection
    {
        $instant = CarbonImmutable::instance($at)->utc();
        $ids = $version->memberships()->where('valid_from', '<=', $instant)
            ->where(fn ($query) => $query->whereNull('valid_to')->orWhere('valid_to', '>', $instant))
            ->whereIn('trading_state', ['online', 'limit_only'])->pluck('asset_id');

        return Asset::query()->whereIn('id', $ids)->orderBy('symbol')->get();
    }

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
