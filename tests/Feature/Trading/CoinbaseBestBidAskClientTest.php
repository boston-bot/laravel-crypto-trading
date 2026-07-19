<?php

namespace Tests\Feature\Trading;

use App\Models\BrokerCredential;
use App\Services\Broker\Coinbase\CoinbaseClient;
use App\Services\Broker\Coinbase\CoinbaseJwtSigner;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class CoinbaseBestBidAskClientTest extends TestCase
{
    public function test_client_sends_repeated_product_ids_query_parameters(): void
    {
        $signer = Mockery::mock(CoinbaseJwtSigner::class);
        $signer->shouldReceive('buildToken')->andReturn('jwt-token');
        $this->app->instance(CoinbaseJwtSigner::class, $signer);

        Http::fake([
            'https://api.coinbase.com/api/v3/brokerage/best_bid_ask*' => Http::response([
                'pricebooks' => [
                    ['product_id' => 'BTC-USD'],
                    ['product_id' => 'ETH-USD'],
                ],
            ], 200),
        ]);

        $credential = new BrokerCredential([
            'broker' => 'coinbase',
            'label' => 'test',
            'api_key_ref' => 'private-key-value',
            'secret_ref' => 'api-key-value',
            'status' => 'active',
        ]);

        $rows = app(CoinbaseClient::class)->getBestBidAsk($credential, ['BTC', 'ETH']);

        $this->assertCount(2, $rows);

        Http::assertSent(function (Request $request): bool {
            $url = $request->url();

            return str_contains($url, '/api/v3/brokerage/best_bid_ask')
                && str_contains($url, 'product_ids=BTC-USD')
                && str_contains($url, 'product_ids=ETH-USD')
                && ! str_contains($url, '%2C')
                && ! str_contains($url, 'product_ids%5B');
        });
    }

    public function test_client_falls_back_to_per_product_requests_when_batch_contains_invalid_id(): void
    {
        $signer = Mockery::mock(CoinbaseJwtSigner::class);
        $signer->shouldReceive('buildToken')->andReturn('jwt-token');
        $this->app->instance(CoinbaseJwtSigner::class, $signer);

        Http::fake(function (Request $request) {
            $url = $request->url();
            if (! str_contains($url, '/api/v3/brokerage/best_bid_ask')) {
                return Http::response([], 404);
            }

            $query = (string) parse_url($url, PHP_URL_QUERY);

            if (str_contains($query, 'product_ids=BTC-USD') && str_contains($query, 'product_ids=BAD-USD')) {
                return Http::response([
                    'error' => 'INVALID_ARGUMENT',
                    'message' => 'invalid product_id provided: "BTC-USD,BAD-USD"',
                ], 400);
            }

            if ($query === 'product_ids=BTC-USD') {
                return Http::response([
                    'pricebooks' => [
                        ['product_id' => 'BTC-USD'],
                    ],
                ], 200);
            }

            if ($query === 'product_ids=BAD-USD') {
                return Http::response([
                    'error' => 'INVALID_ARGUMENT',
                    'message' => 'invalid product_id provided: "BAD-USD"',
                ], 400);
            }

            return Http::response([], 500);
        });

        $credential = new BrokerCredential([
            'broker' => 'coinbase',
            'label' => 'test',
            'api_key_ref' => 'private-key-value',
            'secret_ref' => 'api-key-value',
            'status' => 'active',
        ]);

        $rows = app(CoinbaseClient::class)->getBestBidAsk($credential, ['BTC', 'BAD']);

        $this->assertCount(1, $rows);
        $this->assertSame('BTC-USD', $rows[0]['product_id'] ?? null);
    }
}
