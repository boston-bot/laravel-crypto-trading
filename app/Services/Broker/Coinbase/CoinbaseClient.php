<?php

namespace App\Services\Broker\Coinbase;

use App\Models\BrokerCredential;
use App\Services\Broker\BrokerException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Arr;
use JsonException;
use Throwable;

class CoinbaseClient
{
    public function __construct(
        private readonly HttpFactory $http,
        private readonly CoinbaseJwtSigner $signer,
    ) {
    }

    public function ping(BrokerCredential $credential): bool
    {
        $this->getAccounts($credential);

        return true;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getAccounts(BrokerCredential $credential): array
    {
        return $this->extractRecords(
            $this->request(
                $credential,
                'GET',
                (string) config('broker.coinbase.endpoints.accounts'),
            ),
            ['accounts', 'data.accounts', 'results']
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getAssets(BrokerCredential $credential): array
    {
        return $this->extractRecords(
            $this->request(
                $credential,
                'GET',
                (string) config('broker.coinbase.endpoints.assets'),
            ),
            ['products', 'data.products', 'results']
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getPositions(BrokerCredential $credential): array
    {
        return $this->getAccounts($credential);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getOrders(BrokerCredential $credential): array
    {
        return $this->extractRecords(
            $this->request(
                $credential,
                'GET',
                (string) config('broker.coinbase.endpoints.orders'),
            ),
            ['orders', 'data.orders', 'results']
        );
    }

    /**
     * @param  array<int, string>  $symbols
     * @return array<int, array<string, mixed>>
     */
    public function getBestBidAsk(BrokerCredential $credential, array $symbols = []): array
    {
        $query = [];
        if ($symbols !== []) {
            $query['product_ids'] = implode(',', array_values(array_map(
                fn (string $symbol): string => $this->normalizeProductId($symbol),
                $symbols,
            )));
        }

        return $this->extractRecords(
            $this->request(
                $credential,
                'GET',
                (string) config('broker.coinbase.endpoints.quotes'),
                query: $query,
            ),
            ['pricebooks', 'books', 'results', 'data.results']
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getCandles(
        BrokerCredential $credential,
        string $symbol,
        string $timeframe = '1d',
        int $limit = 350,
    ): array {
        [$granularity, $seconds] = $this->resolveGranularity($timeframe);
        $end = now()->utc();
        $start = $end->copy()->subSeconds($seconds * max(1, $limit));
        $productId = $this->normalizeProductId($symbol);

        $endpoint = str_replace(
            '{product_id}',
            $productId,
            (string) config('broker.coinbase.endpoints.candles'),
        );

        $rows = $this->extractRecords(
            $this->request(
                $credential,
                'GET',
                $endpoint,
                query: [
                    'start' => (string) $start->getTimestamp(),
                    'end' => (string) $end->getTimestamp(),
                    'granularity' => $granularity,
                    'limit' => max(10, min(350, $limit)),
                ],
            ),
            ['candles', 'results']
        );

        return collect($rows)
            ->filter(fn (array $row): bool => isset($row['start']))
            ->map(fn (array $row): array => [
                'open_time' => now()->createFromTimestampUTC((int) $row['start'])->toIso8601String(),
                'close_time' => now()->createFromTimestampUTC(((int) $row['start']) + $seconds)->toIso8601String(),
                'low' => (float) ($row['low'] ?? 0.0),
                'high' => (float) ($row['high'] ?? 0.0),
                'open' => (float) ($row['open'] ?? 0.0),
                'close' => (float) ($row['close'] ?? 0.0),
                'volume' => (float) ($row['volume'] ?? 0.0),
                'turnover_usd' => (float) ($row['volume_in_quote'] ?? 0.0),
                'metadata' => $row,
            ])
            ->sortBy('open_time')
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function placeOrder(BrokerCredential $credential, array $payload): array
    {
        return $this->request(
            $credential,
            'POST',
            (string) config('broker.coinbase.endpoints.place_order'),
            $payload,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function getOrder(BrokerCredential $credential, string $externalOrderId): array
    {
        $base = rtrim((string) config('broker.coinbase.endpoints.order'), '/');
        $endpoint = $base.'/'.$externalOrderId;

        return $this->request($credential, 'GET', $endpoint);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function request(
        BrokerCredential $credential,
        string $method,
        string $endpoint,
        array $payload = [],
        array $query = [],
    ): array {
        $apiKey = $this->resolveCredentialValue($credential->secret_ref)
            ?: (string) config('broker.coinbase.api_key', '');
        $privateKey = $this->resolveCredentialValue($credential->api_key_ref)
            ?: (string) config('broker.coinbase.api_private_key', '');

        if ($apiKey === '' || $privateKey === '') {
            throw new BrokerException('Coinbase API credentials are missing (api key or private key).');
        }

        $path = $this->normalizePath($endpoint);
        $url = rtrim((string) config('broker.coinbase.base_url'), '/').$path;
        $host = (string) parse_url((string) config('broker.coinbase.base_url'), PHP_URL_HOST);
        if ($host === '') {
            throw new BrokerException('Coinbase base URL host is not configured.');
        }

        $body = $this->encodeBody($payload);
        $jwt = $this->signer->buildToken(
            method: strtoupper($method),
            host: $host,
            path: $path,
            apiKey: $apiKey,
            privateKey: $privateKey,
        );

        try {
            $request = $this->httpRequest()->withToken($jwt);
            if ($body !== '') {
                $request = $request->withBody($body, 'application/json');
            }

            $response = $request->send($method, $url, [
                'query' => $query,
            ]);
        } catch (Throwable $exception) {
            throw new BrokerException('Coinbase request failed: '.$exception->getMessage(), previous: $exception);
        }

        if (! $response->successful()) {
            throw new BrokerException(sprintf(
                'Coinbase API request failed (%s): %s',
                $response->status(),
                $response->body(),
            ));
        }

        return [
            'status' => $response->status(),
            'headers' => $response->headers(),
            'body' => $response->json() ?? [],
        ];
    }

    private function httpRequest(): PendingRequest
    {
        return $this->http
            ->acceptJson()
            ->timeout((int) config('broker.coinbase.timeout_seconds', 10))
            ->retry(
                (int) config('broker.coinbase.retry.times', 3),
                (int) config('broker.coinbase.retry.sleep_ms', 200),
                throw: false,
            );
    }

    private function resolveCredentialValue(?string $value): string
    {
        if (! is_string($value) || $value === '') {
            return '';
        }

        if (str_starts_with($value, 'env:')) {
            return (string) env(substr($value, 4), '');
        }

        return $value;
    }

    private function normalizePath(string $endpoint): string
    {
        return '/'.ltrim($endpoint, '/');
    }

    private function normalizeProductId(string $symbol): string
    {
        $upper = strtoupper(trim($symbol));
        if ($upper === '') {
            return $upper;
        }

        if (str_contains($upper, '-')) {
            return $upper;
        }

        $quote = strtoupper((string) config('broker.coinbase.quote_currency', 'USD'));

        return $upper.'-'.$quote;
    }

    /**
     * @return array{0: string, 1: int}
     */
    private function resolveGranularity(string $timeframe): array
    {
        return match ($timeframe) {
            '4h' => ['FOUR_HOUR', 14400],
            default => ['ONE_DAY', 86400],
        };
    }

    /**
     * @param  array<string, mixed>  $response
     * @param  array<int, string>  $preferredPaths
     * @return array<int, array<string, mixed>>
     */
    private function extractRecords(array $response, array $preferredPaths): array
    {
        $body = Arr::get($response, 'body', []);
        if (! is_array($body)) {
            return [];
        }

        foreach ($preferredPaths as $path) {
            $candidate = data_get($body, $path);
            if (is_array($candidate) && $candidate !== []) {
                return array_is_list($candidate) ? $candidate : [$candidate];
            }
        }

        if (array_is_list($body)) {
            return $body;
        }

        return $body === [] ? [] : [$body];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function encodeBody(array $payload): string
    {
        if ($payload === []) {
            return '';
        }

        try {
            return (string) json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $exception) {
            throw new BrokerException('Failed to encode Coinbase request body: '.$exception->getMessage(), previous: $exception);
        }
    }
}
