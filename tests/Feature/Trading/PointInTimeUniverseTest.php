<?php

namespace Tests\Feature\Trading;

use App\Models\Asset;
use App\Models\UniverseVersion;
use App\Models\VenueProduct;
use App\Services\Strategy\UniverseSelectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PointInTimeUniverseTest extends TestCase
{
    use RefreshDatabase;

    public function test_versions_use_product_evidence_valid_at_the_requested_time(): void
    {
        $btc = Asset::query()->create(['broker' => 'coinbase', 'symbol' => 'BTC', 'asset_type' => 'crypto', 'is_enabled' => true, 'is_tradable' => true]);
        $new = Asset::query()->create(['broker' => 'coinbase', 'symbol' => 'NEW', 'asset_type' => 'crypto', 'is_enabled' => true, 'is_tradable' => true]);
        VenueProduct::query()->create(['asset_id' => $btc->id, 'venue' => 'coinbase', 'product_id' => 'BTC-USD', 'base_asset' => 'BTC', 'quote_asset' => 'USD', 'status' => 'online', 'valid_from' => '2025-01-01']);
        VenueProduct::query()->create(['asset_id' => $new->id, 'venue' => 'coinbase', 'product_id' => 'NEW-USD', 'base_asset' => 'NEW', 'quote_asset' => 'USD', 'status' => 'online', 'valid_from' => '2026-06-01']);

        $version = app(UniverseSelectionService::class)->buildVersionAt(Carbon::parse('2026-01-01T00:00:00Z'));

        $this->assertSame(['BTC'], $version->symbols_json);
        $this->assertDatabaseCount('universe_memberships', 1);
        $this->assertSame(['BTC'], app(UniverseSelectionService::class)->assetsForVersionAt($version, Carbon::parse('2026-01-02'))->pluck('symbol')->all());
    }
}
