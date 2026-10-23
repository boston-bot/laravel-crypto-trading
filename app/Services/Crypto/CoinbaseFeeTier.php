<?php

declare(strict_types=1);

namespace App\Services\Crypto;

use App\Enums\BrokerType;
use App\Models\BrokerCredential;
use App\Services\Broker\Coinbase\CoinbaseJwtSigner;
use App\Support\Mcp\Decimal;
use App\Support\Mcp\ExactJsonNumbers;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/** One fixed read-only endpoint. Account-tier evidence cannot establish product-specific all-in costs. */
final class CoinbaseFeeTier
{
    public const PATH = '/api/v3/brokerage/transaction_summary';

    public const SOURCE = 'https://api.coinbase.com'.self::PATH;

    public function __construct(private CoinbaseJwtSigner $signer) {}

    public function read(BrokerCredential $credential): array
    {
        try {
            if ($credential->broker !== BrokerType::COINBASE || $credential->status !== 'active') {
                throw new RuntimeException;
            }
            $key = $this->value($credential->secret_ref, 'broker.coinbase.api_key');
            $secret = $this->value($credential->api_key_ref, 'broker.coinbase.api_private_key');
            if ($key === '' || $secret === '') {
                throw new RuntimeException;
            }
            $started = CarbonImmutable::now();
            $token = $this->signer->buildToken('GET', 'api.coinbase.com', self::PATH, $key, $secret);
            $response = Http::acceptJson()->withToken($token)->connectTimeout(5)->timeout(15)
                ->withOptions(['allow_redirects' => false])->get(self::SOURCE, ['product_type' => 'SPOT', 'product_venue' => 'CBE']);
            if ($response->status() !== 200 || strlen($response->body()) > 131072) {
                throw new RuntimeException;
            }
            $body = ExactJsonNumbers::decode($response->body());
            $tier = $body['fee_tier'] ?? null;
            if (! is_array($tier) || ! is_string($tier['pricing_tier'] ?? null) || strlen($tier['pricing_tier']) > 128) {
                throw new RuntimeException;
            }
            $rates = [];
            foreach (['maker_fee_rate', 'taker_fee_rate'] as $field) {
                $value = $tier[$field] ?? null;
                if (! is_string($value) || ! preg_match('/^\d{1,2}(?:\.\d{1,18})?$/D', $value) || Decimal::from($value)->compareTo(Decimal::from('1')) > 0) {
                    throw new RuntimeException;
                }
                $rates[$field] = $value;
            }
            $reasons = ['product_applicability_requires_owner_review'];
            if (($body['has_cost_plus_commission'] ?? null) !== false) {
                $reasons[] = 'cost_plus_commission_unknown_or_unsupported';
            }
            $tax = $body['goods_and_services_tax'] ?? null;
            if (! is_array($tax) || ! is_string($tax['rate'] ?? null) ||
                ! preg_match('/^0(?:\.0{1,18})?$/D', $tax['rate'])) {
                $reasons[] = 'tax_unknown_or_requires_all_in_review';
            }

            return ['contract' => 'coinbase_spot_fee_tier_review_v1', 'status' => 'requires_product_review',
                'source' => self::SOURCE, 'product_type' => 'SPOT', 'product_venue' => 'CBE',
                'request_started_at' => $started->toISOString(), 'retrieved_at' => CarbonImmutable::now()->toISOString(),
                'source_at' => null, 'response_sha256' => hash('sha256', $response->body()),
                'pricing_tier' => $tier['pricing_tier'], 'rates' => $rates, 'reasons' => $reasons,
                'policy_applied' => false, 'broker_executable' => false];
        } catch (Throwable) {
            // Do not retain an exception chain that may contain signed requests or provider bodies.
            throw new RuntimeException('Coinbase fee-tier read unavailable; no fee schedule or policy was applied.');
        }
    }

    private function value(?string $reference, string $fallback): string
    {
        $value = is_string($reference) && str_starts_with($reference, 'env:') ? (string) env(substr($reference, 4), '') : (string) $reference;

        return $value !== '' ? $value : (string) config($fallback, '');
    }
}
