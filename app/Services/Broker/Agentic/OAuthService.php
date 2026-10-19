<?php

namespace App\Services\Broker\Agentic;

use App\Models\McpConnection;
use App\Models\McpOauthAttempt;
use App\Models\McpOauthCredential;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final class OAuthService
{
    public function __construct(private TrustedHttp $http) {}

    public function discover(): array
    {
        $resource = $this->json('GET', 'https://agent.robinhood.com/.well-known/oauth-protected-resource/mcp/trading');
        if (($resource['resource'] ?? null) !== TrustedHttp::RESOURCE || ! is_array($resource['authorization_servers'] ?? null) || ! is_array($resource['scopes_supported'] ?? null) || ! in_array(TrustedHttp::RESOURCE, $resource['authorization_servers'] ?? [], true)) {
            throw new ReadFailure(ReadError::OAuth);
        }
        $metadata = null;
        foreach (['https://agent.robinhood.com/.well-known/oauth-authorization-server/mcp/trading',
            'https://agent.robinhood.com/.well-known/openid-configuration/mcp/trading',
            TrustedHttp::RESOURCE.'/.well-known/openid-configuration'] as $url) {
            $response = $this->http->send('GET', $url);
            if ($response->status() === 404) {
                continue;
            }
            TrustedHttp::checkStatus($response);
            $metadata = $response->json();
            break;
        }
        if (! is_array($metadata)) {
            throw new ReadFailure(ReadError::OAuth);
        }
        foreach (['code_challenge_methods_supported', 'token_endpoint_auth_methods_supported', 'grant_types_supported', 'response_types_supported'] as $key) {
            if (! is_array($metadata[$key] ?? null)) {
                throw new ReadFailure(ReadError::OAuth);
            }
        }
        if (($metadata['issuer'] ?? null) !== TrustedHttp::RESOURCE
            || ! in_array('S256', $metadata['code_challenge_methods_supported'] ?? [], true)
            || ! in_array('none', $metadata['token_endpoint_auth_methods_supported'] ?? [], true)
            || ! in_array('authorization_code', $metadata['grant_types_supported'] ?? [], true)
            || ! in_array('code', $metadata['response_types_supported'] ?? [], true)
            || ! in_array('internal', $resource['scopes_supported'] ?? [], true)) {
            throw new ReadFailure(ReadError::OAuth);
        }
        foreach (['authorization_endpoint', 'token_endpoint'] as $key) {
            TrustedHttp::validateUrl($metadata[$key] ?? '');
        }
        if (isset($metadata['registration_endpoint'])) {
            TrustedHttp::validateUrl($metadata['registration_endpoint']);
        }

        return array_intersect_key($metadata, array_flip(['issuer', 'authorization_endpoint', 'token_endpoint', 'registration_endpoint',
            'authorization_response_iss_parameter_supported', 'grant_types_supported'])) + ['resource' => TrustedHttp::RESOURCE, 'scope' => 'internal'];
    }

    public function begin(int $userId, string $sessionId): string
    {
        $this->requireEnabled();
        $redirect = (string) config('robinhood_mcp.redirect_uri');
        $parts = parse_url($redirect);
        // This release deliberately supports only a fixed local callback.
        if (! $parts || ! in_array($parts['host'] ?? '', ['127.0.0.1', 'localhost'], true)
            || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true) || isset($parts['query']) || isset($parts['fragment'])
            || isset($parts['user']) || ($parts['path'] ?? '') !== '/mcp/robinhood/callback') {
            throw new ReadFailure(ReadError::OAuth);
        }
        $metadata = $this->discover();
        $connection = McpConnection::firstOrCreate(['user_id' => $userId]);
        $credential = McpOauthCredential::where('mcp_connection_id', $connection->id)->first();
        $clientId = config('robinhood_mcp.client_id') ?: $credential?->client_id;
        if (! $clientId) {
            if (! isset($metadata['registration_endpoint'])) {
                throw new ReadFailure(ReadError::Unsupported);
            }
            // Only called from the operator's CSRF-protected Connect action; no brokerage account creation.
            $registered = $this->json('POST', $metadata['registration_endpoint'], ['json' => [
                'client_name' => 'Local Investing Monitor', 'redirect_uris' => [$redirect],
                'grant_types' => ['authorization_code', 'refresh_token'], 'response_types' => ['code'],
                'token_endpoint_auth_method' => 'none',
            ]]);
            $clientId = $registered['client_id'] ?? null;
            if (! is_string($clientId) || $clientId === '' || ($registered['token_endpoint_auth_method'] ?? 'none') !== 'none') {
                throw new ReadFailure(ReadError::OAuth);
            }
        }
        $state = bin2hex(random_bytes(32));
        $verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
        DB::transaction(function () use ($connection, $userId, $sessionId, $state, $verifier, $redirect, $clientId, $metadata): void {
            McpConnection::whereKey($connection->id)->lockForUpdate()->firstOrFail();
            McpOauthAttempt::where('mcp_connection_id', $connection->id)->whereNull('consumed_at')->update(['consumed_at' => now()]);
            McpOauthAttempt::create(['mcp_connection_id' => $connection->id, 'user_id' => $userId,
                'state_hash' => hash('sha256', $state), 'session_hash' => hash('sha256', $sessionId),
                'verifier' => $verifier, 'redirect_uri' => $redirect, 'client_id' => $clientId,
                'oauth_metadata' => $metadata, 'expires_at' => now()->addMinutes(10)]);
        });

        return $metadata['authorization_endpoint'].'?'.http_build_query([
            'response_type' => 'code', 'client_id' => $clientId, 'redirect_uri' => $redirect,
            'state' => $state, 'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256', 'resource' => TrustedHttp::RESOURCE, 'scope' => $metadata['scope'],
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function complete(int $userId, string $sessionId, array $query): void
    {
        $this->requireEnabled();
        $state = $query['state'] ?? null;
        if (! is_string($state) || strlen($state) !== 64) {
            throw new ReadFailure(ReadError::OAuth);
        }
        // Consume before exchanging: never replay a code after a lost response.
        $attempt = DB::transaction(function () use ($state, $userId, $sessionId): McpOauthAttempt {
            $attempt = McpOauthAttempt::where('state_hash', hash('sha256', $state))->lockForUpdate()->first();
            if (! $attempt || $attempt->user_id !== $userId || $attempt->consumed_at || $attempt->expires_at->isPast()
                || ! hash_equals($attempt->session_hash, hash('sha256', $sessionId))) {
                throw new ReadFailure(ReadError::OAuth);
            }
            $attempt->update(['consumed_at' => now()]);

            return $attempt;
        });
        $failure = DB::transaction(function () use ($attempt, $query): ?ReadFailure {
            $connection = McpConnection::whereKey($attempt->mcp_connection_id)->lockForUpdate()->firstOrFail();
            if (McpOauthAttempt::where('mcp_connection_id', $connection->id)->max('id') !== $attempt->id) {
                return new ReadFailure(ReadError::OAuth);
            }
            $metadata = $attempt->oauth_metadata;
            try {
                if (isset($query['error']) || ! is_string($query['code'] ?? null) || $query['code'] === ''
                    || (($metadata['authorization_response_iss_parameter_supported'] ?? false) && ($query['iss'] ?? null) !== $metadata['issuer'])
                    || (isset($query['iss']) && $query['iss'] !== $metadata['issuer'])) {
                    throw new ReadFailure(ReadError::OAuth);
                }
                $tokens = $this->json('POST', $metadata['token_endpoint'], ['form_params' => [
                    'grant_type' => 'authorization_code', 'code' => $query['code'], 'client_id' => $attempt->client_id,
                    'redirect_uri' => $attempt->redirect_uri, 'code_verifier' => $attempt->verifier,
                    'resource' => TrustedHttp::RESOURCE,
                ]]);
                $values = $this->tokenValues($tokens, $metadata);
                McpOauthCredential::updateOrCreate(['mcp_connection_id' => $connection->id], $values + ['client_id' => $attempt->client_id, 'oauth_metadata' => $metadata]);
                $connection->update(['status' => 'connected', 'auth_generation' => $connection->auth_generation + 1, 'error_code' => null, 'bound_account_id' => null,
                    'account_role' => null, 'bound_at' => null, 'bound_by' => null]);

                return null;
            } catch (ReadFailure $failure) {
                $connection->update(['status' => 'reconnect_required', 'error_code' => $failure->reason->value]);
                McpOauthCredential::where('mcp_connection_id', $connection->id)->update(['access_token' => null, 'refresh_token' => null, 'expires_at' => null]);

                return $failure;
            }
        });
        if ($failure) {
            throw $failure;
        }
    }

    /** The returned token stays valid for at least $minValidSeconds, so a reused MCP session cannot outlive it. */
    public function accessToken(McpConnection $connection, int $minValidSeconds = 30): string
    {
        return $this->credentialToken($connection, false, max(30, $minValidSeconds));
    }

    public function renew(McpConnection $connection): string
    {
        return $this->credentialToken($connection, true, 30);
    }

    /**
     * No database transaction or row lock is held during the token request. A cache lock serializes refreshes
     * across workers, and the write-back only applies if no reconnect or other rotation replaced the credential.
     */
    private function credentialToken(McpConnection $connection, bool $forceRenewal, int $minValidSeconds): string
    {
        $this->requireEnabled();
        $state = $this->tokenState($connection, $forceRenewal, $minValidSeconds);
        if (! is_array($state)) {
            return $this->tokenResult($state);
        }
        $lock = Cache::lock('mcp-oauth-refresh:'.$connection->id, 30);
        try {
            $lock->block(20);
        } catch (LockTimeoutException) {
            throw new ReadFailure(ReadError::Timeout);
        }
        try {
            // Another worker may have refreshed while this one waited for the lock.
            $state = $this->tokenState($connection, $forceRenewal, $minValidSeconds);
            if (! is_array($state)) {
                return $this->tokenResult($state);
            }
            $values = null;
            $outcome = null;
            try {
                if (! $state['refresh_token']) {
                    throw new ReadFailure(ReadError::Authentication);
                }
                $tokens = $this->json('POST', $state['metadata']['token_endpoint'], ['form_params' => [
                    'grant_type' => 'refresh_token', 'refresh_token' => $state['refresh_token'],
                    'client_id' => $state['client_id'], 'resource' => TrustedHttp::RESOURCE,
                ]]);
                $values = $this->tokenValues($tokens, $state['metadata']);
                if (! isset($tokens['refresh_token'])) {
                    $values['refresh_token'] = $state['refresh_token'];
                }
            } catch (ReadFailure $failure) {
                $outcome = $failure;
            }

            return $this->tokenResult(DB::transaction(function () use ($connection, $state, $values, $outcome, $minValidSeconds): string|ReadFailure {
                $current = McpConnection::whereKey($connection->id)->lockForUpdate()->firstOrFail();
                $credential = McpOauthCredential::where('mcp_connection_id', $connection->id)->lockForUpdate()->first();
                if (! $credential || $current->auth_generation !== $state['generation'] || $current->status === 'reconnect_required'
                    || ! hash_equals((string) $state['refresh_token'], (string) $credential->refresh_token)) {
                    // A reconnect or another rotation replaced this credential while the request ran; never overwrite or wipe it.
                    return $current->status !== 'reconnect_required' && $credential?->access_token && $credential->expires_at?->isAfter(now()->addSeconds($minValidSeconds))
                        ? $credential->access_token : new ReadFailure(ReadError::Authentication);
                }
                if ($outcome === null) {
                    $credential->update($values);

                    return $credential->access_token;
                }
                if ($outcome->reason === ReadError::RateLimited) {
                    return $outcome;
                }
                // Revocation or a lost rotation response requires reconnect; no blind refresh retry.
                $current->update(['status' => 'reconnect_required', 'error_code' => $outcome->reason->value]);
                $credential->update(['access_token' => null, 'refresh_token' => null, 'expires_at' => null]);

                return $outcome;
            }));
        } finally {
            $lock->release();
        }
    }

    /** @return string|ReadFailure|array{refresh_token: ?string, client_id: ?string, metadata: array, generation: int} */
    private function tokenState(McpConnection $connection, bool $forceRenewal, int $minValidSeconds): string|ReadFailure|array
    {
        $current = McpConnection::whereKey($connection->id)->firstOrFail();
        $credential = McpOauthCredential::where('mcp_connection_id', $connection->id)->first();
        if ($current->status === 'reconnect_required' || ! $credential?->access_token) {
            return new ReadFailure(ReadError::Authentication);
        }
        if (! $forceRenewal && $credential->expires_at && $credential->expires_at->isAfter(now()->addSeconds($minValidSeconds))) {
            return $credential->access_token;
        }

        return ['refresh_token' => $credential->refresh_token, 'client_id' => $credential->client_id,
            'metadata' => $credential->oauth_metadata, 'generation' => $current->auth_generation];
    }

    private function tokenResult(string|ReadFailure $result): string
    {
        if ($result instanceof ReadFailure) {
            throw $result;
        }

        return $result;
    }

    public function requireEnabled(): void
    {
        if (! config('robinhood_mcp.enabled')) {
            throw new ReadFailure(ReadError::Disabled);
        }
    }

    private function tokenValues(array $tokens, array $metadata): array
    {
        if (! is_string($tokens['access_token'] ?? null) || $tokens['access_token'] === ''
            || ! is_string($tokens['token_type'] ?? null) || strtolower($tokens['token_type']) !== 'bearer' || ! is_int($tokens['expires_in'] ?? null)
            || $tokens['expires_in'] < 1 || $tokens['expires_in'] > 31536000
            || (isset($tokens['refresh_token']) && (! is_string($tokens['refresh_token']) || $tokens['refresh_token'] === ''))
            || (isset($tokens['scope']) && $tokens['scope'] !== $metadata['scope'])
            || (isset($tokens['resource']) && $tokens['resource'] !== TrustedHttp::RESOURCE)) {
            throw new ReadFailure(ReadError::OAuth);
        }

        return ['access_token' => $tokens['access_token'], 'refresh_token' => $tokens['refresh_token'] ?? null,
            'expires_at' => now()->addSeconds($tokens['expires_in'])];
    }

    private function json(string $method, string $url, array $options = []): array
    {
        $response = $this->http->send($method, $url, $options);
        if ($response->status() === 400 && in_array($response->json('error'), ['invalid_grant', 'invalid_client'], true)) {
            throw new ReadFailure(ReadError::Authentication);
        }
        TrustedHttp::checkStatus($response);
        $json = $response->json();
        if (! is_array($json)) {
            throw new ReadFailure(ReadError::Malformed);
        }

        return $json;
    }
}
