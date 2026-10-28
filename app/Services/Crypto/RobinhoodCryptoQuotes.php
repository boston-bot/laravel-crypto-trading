<?php

declare(strict_types=1);

namespace App\Services\Crypto;

use App\Enums\BrokerType;
use App\Models\BrokerCredential;
use App\Services\Broker\Robinhood\RobinhoodSigner;
use App\Support\Mcp\Decimal;
use App\Support\Mcp\ExactJsonNumbers;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Read-only Robinhood Crypto quotes for a cost comparison: one fixed signed GET of best bid/ask including Robinhood's
 * spread. Fixed host and path, no retries or redirects, and failures never carry provider bodies or signed requests.
 * Never used by an order path.
 */
final class RobinhoodCryptoQuotes
{
    public const SOURCE = 'https://trading.robinhood.com/api/v1/crypto/marketdata/best_bid_ask/';

    private const PATH = '/api/v1/crypto/marketdata/best_bid_ask/';

    public function __construct(private RobinhoodSigner $signer) {}

    /** @return array<string, array{bid: string, ask: string, price: string, source_at: string}> */
    public function read(BrokerCredential $credential, array $symbols): array
    {
        try {
            if ($credential->broker !== BrokerType::ROBINHOOD || $credential->status !== 'active' || $symbols === [] || count($symbols) > 10) {
                throw new RuntimeException;
            }
            foreach ($symbols as $symbol) {
                if (! is_string($symbol) || ! preg_match(CoinbasePublicMarketData::PRODUCT_PATTERN, $symbol)) {
                    throw new RuntimeException;
                }
            }
            $key = $this->value($credential->secret_ref, 'broker.robinhood.x_api_key');
            $secret = $this->value($credential->api_key_ref, 'broker.robinhood.private_api_key');
            if ($key === '' || $secret === '') {
                throw new RuntimeException;
            }
            $path = self::PATH.'?'.implode('&', array_map(fn ($symbol) => 'symbol='.rawurlencode($symbol), $symbols));
            $headers = $this->signer->buildHeaders('GET', $path, '', $key, $secret);
            $response = Http::withHeaders($headers)->connectTimeout(5)->timeout(15)->withOptions(['allow_redirects' => false])
                ->get('https://trading.robinhood.com'.$path);
            if ($response->status() !== 200 || strlen($response->body()) > 131072) {
                throw new RuntimeException;
            }
            $rows = ExactJsonNumbers::decode($response->body())['results'] ?? null;
            if (! is_array($rows) || ! array_is_list($rows)) {
                throw new RuntimeException;
            }
            $quotes = [];
            foreach ($rows as $row) {
                $symbol = $row['symbol'] ?? null;
                $values = [$row['bid_inclusive_of_sell_spread'] ?? null, $row['ask_inclusive_of_buy_spread'] ?? null, $row['price'] ?? null];
                if (! in_array($symbol, $symbols, true) || isset($quotes[$symbol]) || ! is_string($row['timestamp'] ?? null)) {
                    throw new RuntimeException;
                }
                foreach ($values as $value) {
                    if (! is_string($value) || ! preg_match('/^\d{1,12}(?:\.\d{1,18})?$/D', $value) || Decimal::from($value)->compareTo(Decimal::from('0')) <= 0) {
                        throw new RuntimeException;
                    }
                }
                [$bid, $ask, $price] = $values;
                if (Decimal::from($ask)->compareTo(Decimal::from($bid)) < 0) {
                    throw new RuntimeException;
                }
                $quotes[$symbol] = ['bid' => $bid, 'ask' => $ask, 'price' => $price, 'source_at' => CarbonImmutable::parse($row['timestamp'])->utc()->toISOString()];
            }

            return $quotes;
        } catch (Throwable) {
            // Do not retain an exception chain that may contain signed requests or provider bodies.
            throw new RuntimeException('Robinhood Crypto quote read unavailable.');
        }
    }

    private function value(?string $reference, string $fallback): string
    {
        $value = is_string($reference) && str_starts_with($reference, 'env:') ? (string) env(substr($reference, 4), '') : (string) $reference;

        return $value !== '' ? $value : (string) config($fallback, '');
    }
}
