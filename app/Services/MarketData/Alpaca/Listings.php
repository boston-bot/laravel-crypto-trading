<?php

declare(strict_types=1);

namespace App\Services\MarketData\Alpaca;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Read-only asset reference used only as US exchange-listing evidence. One fixed GET path on the configured
 * account host; no account, position, order or mutation endpoint is reachable from this class.
 */
final class Listings
{
    public const HOSTS = ['paper' => 'https://paper-api.alpaca.markets', 'live' => 'https://api.alpaca.markets'];

    public const CONTRACT = 'alpaca_assets_v2_listing_v1';

    /** Reviewed national-exchange values. OTC and crypto are known not to be US exchange listings. */
    public const US_EXCHANGES = ['AMEX', 'ARCA', 'BATS', 'NASDAQ', 'NYSE', 'NYSEARCA'];

    private const EXCHANGES = [...self::US_EXCHANGES, 'OTC', 'CRYPTO'];

    private const CLASSES = ['us_equity', 'us_option', 'crypto', 'crypto_perp', 'treasury', 'corporate', 'global_equity', 'us_index', 'us_equity_chain', 'ipo'];

    public function read(string $symbol): array
    {
        $host = self::HOSTS[config('alpaca_data.account_environment')] ?? null;
        $result = ['provider' => ($host ?? 'unconfigured').'/v2/assets', 'contract' => self::CONTRACT, 'symbol' => $symbol,
            'retrieved_at' => CarbonImmutable::now()->toISOString(), 'complete' => false, 'error' => null,
            'asset' => null, 'us_exchange_listed' => null, 'reason' => null];
        $error = match (true) {
            ! config('alpaca_data.enabled') || ! config('alpaca_data.reference_enabled') => 'disabled',
            $host === null => 'account_environment_invalid',
            ! is_string(config('alpaca_data.key')) || config('alpaca_data.key') === '' || ! is_string(config('alpaca_data.secret')) || config('alpaca_data.secret') === '' => 'credentials_missing',
            ! preg_match('/^[A-Z][A-Z0-9.-]{0,15}$/D', $symbol) => 'invalid_symbol',
            default => null,
        };
        if ($error !== null) {
            return array_replace($result, ['error' => $error]);
        }
        try {
            $response = Http::withHeaders(['APCA-API-KEY-ID' => config('alpaca_data.key'), 'APCA-API-SECRET-KEY' => config('alpaca_data.secret')])
                ->timeout(10)->connectTimeout(5)->withOptions(['allow_redirects' => false])->get($host.'/v2/assets/'.rawurlencode($symbol));
            $result['retrieved_at'] = CarbonImmutable::now()->toISOString();
            if (! $response->successful()) {
                // An asset unknown to this provider is not evidence that the symbol is unlisted.
                return array_replace($result, ['error' => match ($response->status()) {
                    401 => 'authentication_failed', 403 => 'access_denied', 404 => 'asset_not_found', 429 => 'rate_limited', default => 'http_failure',
                }]);
            }
            if (strlen($response->body()) > 64 * 1024) {
                return array_replace($result, ['error' => 'response_limit']);
            }
            $asset = $response->json();
            if (! is_array($asset) || ($asset['symbol'] ?? null) !== $symbol || ! in_array($asset['class'] ?? null, self::CLASSES, true)
                || ! in_array($asset['status'] ?? null, ['active', 'inactive'], true) || ! is_string($asset['id'] ?? null)) {
                return array_replace($result, ['error' => 'invalid_or_unreviewed_response']);
            }
            if (! in_array($asset['exchange'] ?? null, self::EXCHANGES, true)) {
                return array_replace($result, ['error' => 'unreviewed_exchange_value']);
            }
            $listed = $asset['class'] === 'us_equity' && $asset['status'] === 'active' && in_array($asset['exchange'], self::US_EXCHANGES, true);
            $reason = match (true) {
                $listed => 'us_exchange_listed',
                $asset['class'] !== 'us_equity' => 'not_us_equity',
                $asset['status'] !== 'active' => 'asset_inactive',
                default => 'not_us_exchange_listed',
            };
            // Only reviewed reference fields are kept; tradability/margin flags describe this provider, not Robinhood.
            $kept = ['class' => $asset['class'], 'exchange' => $asset['exchange'], 'status' => $asset['status'],
                'fractionable' => is_bool($asset['fractionable'] ?? null) ? $asset['fractionable'] : null];

            return array_replace($result, ['complete' => true, 'asset' => $kept, 'us_exchange_listed' => $listed, 'reason' => $reason]);
        } catch (ConnectionException) {
            return array_replace($result, ['error' => 'transport_unavailable']);
        } catch (\Throwable) {
            return array_replace($result, ['error' => 'transport_or_response_failure']);
        }
    }
}
