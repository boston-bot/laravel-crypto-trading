<?php

namespace Tests\Feature\Crypto;

use App\Models\BrokerCredential;
use App\Models\User;
use App\Services\Broker\Coinbase\CoinbaseJwtSigner;
use App\Services\Crypto\CoinbaseFeeTier;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class CoinbaseFeeTierTest extends TestCase
{
    use RefreshDatabase;

    private function credential(): BrokerCredential
    {
        return new BrokerCredential(['broker' => 'coinbase', 'status' => 'active', 'secret_ref' => 'fake-key', 'api_key_ref' => 'fake-secret']);
    }

    private function body(): array
    {
        return ['fee_tier' => ['pricing_tier' => 'fake-tier', 'maker_fee_rate' => '0.004', 'taker_fee_rate' => '0.006'],
            'has_cost_plus_commission' => false, 'goods_and_services_tax' => ['rate' => '0', 'type' => 'INCLUSIVE'],
            'total_balance' => 'PRIVATE_BALANCE', 'total_fees' => 'PRIVATE_FEES'];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-23T15:00:00Z'));
        $signer = Mockery::mock(CoinbaseJwtSigner::class);
        $signer->shouldReceive('buildToken')->with('GET', 'api.coinbase.com', CoinbaseFeeTier::PATH, 'fake-key', 'fake-secret')->andReturn('fake-signed-token');
        $this->app->instance(CoinbaseJwtSigner::class, $signer);
    }

    public function test_one_fixed_get_returns_redacted_exact_review_evidence_without_creating_a_policy(): void
    {
        config(['broker.coinbase.base_url' => 'https://untrusted.example', 'broker.coinbase.endpoints.transaction_summary' => '/orders']);
        $body = json_encode($this->body(), JSON_THROW_ON_ERROR);
        Http::fake(['api.coinbase.com/*' => Http::response($body)]);
        $report = app(CoinbaseFeeTier::class)->read($this->credential());
        $this->assertSame(['maker_fee_rate' => '0.004', 'taker_fee_rate' => '0.006'], $report['rates']);
        $this->assertSame(['product_applicability_requires_owner_review'], $report['reasons']);
        $this->assertSame(hash('sha256', $body), $report['response_sha256']);
        $this->assertNull($report['source_at']);
        $this->assertSame(now()->toISOString(), $report['retrieved_at']);
        $this->assertSame('requires_product_review', $report['status']);
        $this->assertFalse($report['policy_applied']);
        foreach (['PRIVATE_BALANCE', 'PRIVATE_FEES', 'fake-key', 'fake-secret', 'fake-signed-token'] as $private) {
            $this->assertStringNotContainsString($private, json_encode($report));
        }
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $r) => $r->method() === 'GET' && $r->url() === CoinbaseFeeTier::SOURCE.'?product_type=SPOT&product_venue=CBE'
            && $r->hasHeader('Authorization', 'Bearer fake-signed-token') && $r->body() === '');
        $this->assertDatabaseCount('mcp_paper_instrument_policies', 0);
        $this->assertDatabaseCount('mcp_paper_cash_entries', 0);
        $this->assertDatabaseCount('broker_orders', 0);
    }

    public function test_tax_and_cost_plus_uncertainty_remain_explicit_without_guessing_all_in_rates(): void
    {
        foreach ([[], ['has_cost_plus_commission' => true], ['goods_and_services_tax' => ['rate' => '0.05', 'type' => 'EXCLUSIVE']]] as $change) {
            $body = $change === [] ? ['fee_tier' => $this->body()['fee_tier']] : array_replace($this->body(), $change);
            Http::fake(['api.coinbase.com/*' => Http::response($body)]);
            $report = app(CoinbaseFeeTier::class)->read($this->credential());
            $this->assertSame('requires_product_review', $report['status']);
            $this->assertGreaterThan(1, count($report['reasons']));
            $this->assertArrayNotHasKey('cost_scope', $report);
        }
    }

    public function test_bad_responses_fail_without_retry_redirect_or_secret_exception_details(): void
    {
        foreach ([Http::response(['message' => 'PRIVATE_PROVIDER_BODY'], 401), Http::response('', 302, ['Location' => 'https://untrusted.example']),
            Http::response('PRIVATE_PROVIDER_BODY', 429), Http::response(str_repeat('x', 131073)), Http::response('{bad json'),
            Http::response([]), Http::response(array_replace_recursive($this->body(), ['fee_tier' => ['maker_fee_rate' => '-0.01']])),
            Http::response(array_replace_recursive($this->body(), ['fee_tier' => ['taker_fee_rate' => '1.01']]))] as $response) {
            Http::fake(['api.coinbase.com/*' => $response]);
            try {
                app(CoinbaseFeeTier::class)->read($this->credential());
                $this->fail('Unreviewed response accepted.');
            } catch (RuntimeException $e) {
                $this->assertSame('Coinbase fee-tier read unavailable; no fee schedule or policy was applied.', $e->getMessage());
                $this->assertNull($e->getPrevious());
            }
            Http::assertSentCount(1);
        }
    }

    public function test_missing_or_wrong_venue_credentials_do_not_send_requests(): void
    {
        config(['broker.coinbase.api_key' => '', 'broker.coinbase.api_private_key' => '']);
        foreach ([new BrokerCredential(['broker' => 'coinbase', 'status' => 'active']),
            new BrokerCredential(['broker' => 'robinhood', 'status' => 'active']),
            new BrokerCredential(['broker' => 'coinbase', 'status' => 'disabled'])] as $credential) {
            try {
                app(CoinbaseFeeTier::class)->read($credential);
                $this->fail('Invalid credential accepted.');
            } catch (RuntimeException $e) {
                $this->assertNull($e->getPrevious());
            }
        }
        Http::assertNothingSent();
    }

    public function test_command_requires_operator_before_network_and_cannot_enable_trading(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        config(['mcp.operator_emails' => [$owner->email]]);
        $this->artisan('mcp:crypto-fees')->assertFailed();
        $this->artisan('mcp:crypto-fees', ['--actor' => $other->id])->assertFailed();
        $this->artisan('mcp:crypto-fees', ['--actor' => $owner->id, '--credential' => 'bad'])->assertFailed();
        Http::assertNothingSent();
        $credential = $this->credential();
        $credential->label = 'fake';
        $credential->save();
        Http::fake(['api.coinbase.com/*' => Http::response($this->body())]);
        $this->artisan('mcp:crypto-fees', ['--actor' => $owner->id, '--credential' => $credential->id])
            ->expectsOutputToContain('requires_product_review')->doesntExpectOutputToContain('PRIVATE_BALANCE')->assertSuccessful();
        Http::assertSentCount(1);
        $this->assertFalse(config('crypto.strategy_evaluation_enabled'));
        $this->assertFalse(config('crypto.market_data_enabled'));
        $this->assertFalse(config('broker.coinbase.writes_enabled'));
        $this->assertFalse(config('broker.robinhood.writes_enabled'));
        $this->assertDatabaseCount('mcp_paper_portfolios', 0);
    }
}
