<?php

namespace App\Services\Broker\Robinhood;

use App\Models\BrokerCredential;
use App\Services\Broker\BrokerException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Arr;
use JsonException;
use Throwable;

class RobinhoodClient
{
    public function __construct(
        private readonly HttpFactory $http,
        private readonly RobinhoodSigner $signer,
    ) {}

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
                (string) config('broker.robinhood.endpoints.accounts'),
            ),
            ['results', 'accounts', 'data.results']
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
                (string) config('broker.robinhood.endpoints.assets'),
            ),
            ['results', 'trading_pairs', 'data.tokens']
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getPositions(BrokerCredential $credential): array
    {
        return $this->extractRecords(
            $this->request(
                $credential,
                'GET',
                (string) config('broker.robinhood.endpoints.positions'),
            ),
            ['results', 'holdings', 'data.results']
        );
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
                (string) config('broker.robinhood.endpoints.orders'),
            ),
            ['results', 'orders', 'data.results']
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
            $query['symbols'] = implode(',', array_map(
                fn (string $symbol): string => $this->normalizeMarketDataSymbol($symbol),
                $symbols
            ));
        }

        return $this->extractRecords(
            $this->request(
                $credential,
                'GET',
                (string) config('broker.robinhood.endpoints.quotes'),
                query: $query,
            ),
            ['results', 'quotes', 'data.results']
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getCandles(
        BrokerCredential $credential,
        string $symbol,
        string $interval = 'day',
        string $span = 'year',
    ): array {
        $normalizedSymbol = $this->normalizeMarketDataSymbol($symbol);
        $query = [
            'symbol' => $normalizedSymbol,
            'interval' => $interval,
            'span' => $span,
            'bounds' => '24_7',
        ];

        $messages = [];
        foreach ($this->candleEndpoints($normalizedSymbol) as $endpoint) {
            try {
                return $this->extractRecords(
                    $this->request(
                        $credential,
                        'GET',
                        $endpoint,
                        query: $query,
                    ),
                    ['results', 'candles', 'historicals', 'data.results', 'data.historicals']
                );
            } catch (BrokerException $exception) {
                $messages[] = $exception->getMessage();

                // Try fallback endpoint shapes for Not Found, fail fast on auth/signature errors.
                if (! $this->isHttpStatus($exception->getMessage(), 404)) {
                    throw $exception;
                }
            }
        }

        throw new BrokerException('Robinhood candle endpoint not found for '.$normalizedSymbol.'. '.implode(' | ', $messages));
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
            (string) config('broker.robinhood.endpoints.orders'),
            $payload,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function getOrder(BrokerCredential $credential, string $externalOrderId): array
    {
        $endpoint = rtrim((string) config('broker.robinhood.endpoints.orders'), '/').'/'.$externalOrderId.'/';

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
        $normalizedQuery = $this->normalizeQuery($query);
        $signingPrivateKey = $this->resolveCredentialValue($credential->api_key_ref)
            ?: (string) config('broker.robinhood.private_api_key', '');
        $xApiKey = $this->resolveCredentialValue($credential->secret_ref)
            ?: (string) config('broker.robinhood.x_api_key', '');

        if ($xApiKey === '') {
            $xApiKey = (string) config('broker.robinhood.api_secret', '');
        }

        if ($signingPrivateKey === '') {
            $signingPrivateKey = (string) config('broker.robinhood.signing_private_key', '');
        }

        if ($signingPrivateKey === '') {
            $signingPrivateKey = (string) config('broker.robinhood.api_key', '');
        }

        if ($xApiKey === '' || $signingPrivateKey === '') {
            throw new BrokerException('Robinhood API credentials are missing (x-api-key or signing key).');
        }

        $path = $this->normalizePath($endpoint);
        $signedPath = $this->buildSignedPath($path, $normalizedQuery);
        $body = $this->encodeBody($payload);
        $headers = $this->signer->buildHeaders(
            method: $method,
            path: $signedPath,
            body: $body,
            apiKey: $xApiKey,
            privateKey: $signingPrivateKey,
        );
        $url = rtrim((string) config('broker.robinhood.base_url'), '/').$path;

        try {
            $request = $this->httpRequest()->withHeaders($headers);
            if ($body !== '') {
                $request = $request->withBody($body, 'application/json');
            }

            $response = $request->send($method, $url, [
                'query' => $normalizedQuery,
            ]);
        } catch (Throwable $exception) {
            throw new BrokerException('Robinhood request failed: '.$exception->getMessage(), previous: $exception);
        }

        if (! $response->successful()) {
            throw new BrokerException(sprintf(
                'Robinhood API request failed (%s): %s',
                $response->status(),
                $response->body()
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
            ->timeout((int) config('broker.robinhood.timeout_seconds', 10))
            ->retry(
                (int) config('broker.robinhood.retry.times', 3),
                (int) config('broker.robinhood.retry.sleep_ms', 200),
                throw: false,
            );
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

        $nestedData = Arr::get($body, 'data');
        if (is_array($nestedData)) {
            return array_is_list($nestedData) ? $nestedData : [$nestedData];
        }

        return $body === [] ? [] : [$body];
    }

    private function resolveCredentialValue(?string $value): string
    {
        if (! is_string($value) || $value === '') {
            return '';
        }

        if (str_starts_with($value, 'env:')) {
            return (string) env(substr($value, 4), '');
        }

        if (str_starts_with($value, 'ssm:') || str_starts_with($value, 'secret:')) {
            return '';
        }

        return $value;
    }

    private function normalizePath(string $endpoint): string
    {
        return '/'.ltrim($endpoint, '/');
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function buildSignedPath(string $path, array $query): string
    {
        if ($query === []) {
            return $path;
        }

        $normalized = $this->normalizeQuery($query);
        $queryString = http_build_query($normalized, '', '&', PHP_QUERY_RFC3986);
        if ($queryString === '') {
            return $path;
        }

        return $path.'?'.$queryString;
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function normalizeQuery(array $query): array
    {
        ksort($query);

        foreach ($query as $key => $value) {
            if (is_array($value)) {
                $query[$key] = $this->normalizeQuery($value);
            }
        }

        return $query;
    }

    private function normalizeMarketDataSymbol(string $symbol): string
    {
        $upper = strtoupper(trim($symbol));
        if ($upper === '') {
            return $upper;
        }

        if (str_ends_with($upper, '-USD')) {
            return $upper;
        }

        return $upper.'-USD';
    }

    /**
     * @return array<int, string>
     */
    private function candleEndpoints(string $normalizedSymbol): array
    {
        $configured = rtrim((string) config('broker.robinhood.endpoints.candles'), '/').'/';

        return array_values(array_unique([
            $configured,
            $configured.$normalizedSymbol.'/',
            '/api/v1/crypto/marketdata/'.$normalizedSymbol.'/historicals/',
        ]));
    }

    private function isHttpStatus(string $message, int $status): bool
    {
        return str_contains($message, '('.$status.')');
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
            throw new BrokerException('Failed to encode Robinhood request body: '.$exception->getMessage(), previous: $exception);
        }
    }
}
