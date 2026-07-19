<?php

namespace Tests\Feature\Trading;

use App\Models\Asset;
use App\Services\Execution\OrderIntentValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderIntentParityTest extends TestCase
{
    use RefreshDatabase;

    public function test_shared_intent_is_accepted_without_resizing(): void
    {
        $asset = Asset::query()->create(['broker' => 'coinbase', 'symbol' => 'BTC', 'asset_type' => 'crypto', 'is_enabled' => true, 'is_tradable' => true, 'min_order_notional' => 1, 'quantity_precision' => 8]);
        $intent = json_decode((string) file_get_contents(base_path('contracts/fixtures/order-intent-v1.json')), true, flags: JSON_THROW_ON_ERROR);

        $validated = app(OrderIntentValidator::class)->validate($intent, $asset);

        $this->assertSame($intent['normalized_base_quantity'], $validated['normalized_base_quantity']);
        $this->assertSame($intent['intent_hash'], $validated['intent_hash']);
    }
}
