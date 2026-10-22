<?php

namespace Tests\Feature\Trading;

use App\Models\BrokerCredential;
use App\Services\Broker\Coinbase\CoinbaseJwtSigner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class BrokerKeyPermissionsCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $signer = Mockery::mock(CoinbaseJwtSigner::class);
        $signer->shouldReceive('buildToken')->andReturn('test-jwt');
        $this->app->instance(CoinbaseJwtSigner::class, $signer);
        BrokerCredential::query()->create([
            'broker' => 'coinbase',
            'label' => 'coinbase-test',
            'api_key_ref' => 'test-private-key',
            'secret_ref' => 'test-api-key',
            'status' => 'active',
        ]);
    }

    public function test_view_only_key_is_reported_with_a_single_get(): void
    {
        Http::fake(['api.coinbase.com/api/v3/brokerage/key_permissions' => Http::response([
            'can_view' => true, 'can_trade' => false, 'can_transfer' => false,
            'portfolio_uuid' => 'not-printed', 'portfolio_type' => 'DEFAULT',
        ])]);

        $this->artisan('broker:key-permissions')
            ->expectsOutput('can_view: yes')
            ->expectsOutput('can_trade: no')
            ->expectsOutput('can_transfer: no')
            ->expectsOutput('portfolio_type: DEFAULT')
            ->expectsOutput('This key is view-only.')
            ->doesntExpectOutputToContain('not-printed')
            ->assertSuccessful();

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET');
    }

    public function test_trade_capable_key_is_flagged(): void
    {
        Http::fake(['api.coinbase.com/*' => Http::response(['can_view' => true, 'can_trade' => true, 'can_transfer' => false])]);

        $this->artisan('broker:key-permissions')
            ->expectsOutput('can_trade: yes')
            ->expectsOutputToContain('This key can trade or transfer.')
            ->assertSuccessful();
    }

    public function test_incomplete_response_is_treated_as_trade_capable(): void
    {
        Http::fake(['api.coinbase.com/*' => Http::response(['can_view' => true])]);

        $this->artisan('broker:key-permissions')
            ->expectsOutput('can_trade: unknown')
            ->expectsOutputToContain('treat the key as trade-capable')
            ->assertFailed();
    }

    public function test_robinhood_reports_unverified_without_a_request(): void
    {
        $this->artisan('broker:key-permissions', ['--broker' => 'robinhood'])
            ->expectsOutputToContain('Permissions are unverified')
            ->assertSuccessful();

        Http::assertNothingSent();
    }
}
