<?php

namespace Tests\Support;

use App\Models\McpConnection;
use App\Models\McpOauthCredential;
use App\Models\User;
use App\Services\Broker\Agentic\Capabilities;
use App\Services\Broker\Agentic\Operation;
use App\Services\Broker\Agentic\TrustedHttp;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/** Entirely synthetic. No brokerage captures, identifiers or credentials. */
final class McpFixture
{
    public static function metadata(): array
    {
        return ['issuer' => TrustedHttp::RESOURCE, 'authorization_endpoint' => 'https://robinhood.com/oauth',
            'token_endpoint' => 'https://api.robinhood.com/oauth2/token/', 'registration_endpoint' => 'https://agent.robinhood.com/oauth/trading/register',
            'code_challenge_methods_supported' => ['S256'], 'token_endpoint_auth_methods_supported' => ['none'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'], 'response_types_supported' => ['code'],
            'authorization_response_iss_parameter_supported' => true];
    }

    public static function oauthFake(array $tokenResponse = [], int $status = 200): void
    {
        Http::swap((new Factory)->preventStrayRequests());
        Http::fake([
            'https://agent.robinhood.com/.well-known/oauth-protected-resource/mcp/trading' => Http::response([
                'resource' => TrustedHttp::RESOURCE, 'authorization_servers' => [TrustedHttp::RESOURCE], 'scopes_supported' => ['internal']]),
            'https://agent.robinhood.com/.well-known/oauth-authorization-server/mcp/trading' => Http::response(self::metadata()),
            'https://agent.robinhood.com/oauth/trading/register' => Http::response(['client_id' => 'synthetic-client', 'token_endpoint_auth_method' => 'none']),
            'https://api.robinhood.com/oauth2/token/' => Http::response($tokenResponse ?: self::tokens(), $status),
        ]);
    }

    public static function tokens(): array
    {
        return ['access_token' => 'synthetic-access', 'refresh_token' => 'synthetic-refresh', 'token_type' => 'Bearer', 'expires_in' => 3600, 'scope' => 'internal'];
    }

    public static function connection(?User $user = null): McpConnection
    {
        $connection = McpConnection::create(['user_id' => ($user ?? User::factory()->create())->id, 'status' => 'connected',
            'bound_account_id' => 'synthetic-agentic', 'account_role' => 'agentic_monitoring']);
        McpOauthCredential::create(['mcp_connection_id' => $connection->id, 'client_id' => 'synthetic-client',
            'access_token' => 'synthetic-access', 'refresh_token' => 'synthetic-refresh',
            'expires_at' => now()->addHour(), 'oauth_metadata' => self::metadata() + ['scope' => 'internal', 'resource' => TrustedHttp::RESOURCE]]);

        return $connection;
    }

    public static function tool(Operation $operation): array
    {
        $properties = $operation === Operation::Accounts ? [] : ['account' => ['type' => 'string'], 'cursor' => ['type' => 'string']];

        return ['name' => 'synthetic_read_'.$operation->value, 'inputSchema' => ['type' => 'object', 'properties' => (object) $properties, 'additionalProperties' => false],
            'outputSchema' => ['type' => 'object', 'properties' => ['records' => ['type' => 'array'], 'next_cursor' => ['type' => ['string', 'null']]], 'required' => ['records', 'next_cursor']]];
    }

    public static function configure(McpConnection $connection, Operation $operation): array
    {
        $tool = self::tool($operation);
        $schema = ['name' => $tool['name'], 'inputSchema' => $tool['inputSchema'], 'outputSchema' => $tool['outputSchema']];
        $fields = match ($operation) {
            Operation::Accounts => ['id', 'account_kind'],
            Operation::Positions => ['id', 'account_id', 'instrument_id', 'quantity', 'market_value'],
            Operation::Portfolio => ['account_id', 'cash', 'equity', 'buying_power'],
            Operation::Orders => ['id', 'account_id', 'instrument_id', 'status', 'side', 'quantity', 'filled_quantity'],
            Operation::Instruments => ['id', 'symbol'],
            Operation::Quotes => ['instrument_id', 'bid', 'ask', 'last'],
            Operation::Watchlists => ['id', 'name', 'owner_type', 'item_count'],
            Operation::WatchlistItems => ['id', 'object_type', 'symbol'],
            Operation::CashFlows => ['id', 'account_id', 'amount'],
            Operation::History => ['account_id', 'equity'],
            Operation::Calendar => ['date', 'session_status'],
        };
        $profile = ['tool' => $tool['name'], 'reviewed_read_only' => true, 'fingerprint' => Capabilities::fingerprint($schema),
            'records' => 'records', 'source_as_of' => 'as_of', 'fields' => array_combine($fields, $fields),
            'pagination' => ['kind' => 'cursor', 'argument' => 'cursor', 'next' => 'next_cursor']];
        if ($operation !== Operation::Accounts) {
            $profile['account_argument'] = 'account';
        }
        config(['robinhood_mcp.operations.'.$operation->value => $profile]);
        $manifest = $connection->capability_manifest ?? [];
        $manifest[$tool['name']] = $schema + ['fingerprint' => $profile['fingerprint']];
        $connection->update(['capability_manifest' => $manifest]);

        return $tool;
    }

    public static function rpcFake(array $tools, callable $call): void
    {
        Http::swap((new Factory)->preventStrayRequests());
        Http::fake([TrustedHttp::RESOURCE => function ($request) use ($tools, $call) {
            $rpc = json_decode($request->body(), true);
            if ($request->method() === 'DELETE' || ($rpc['method'] ?? '') === 'notifications/initialized') {
                return Http::response('', 202);
            }
            $result = match ($rpc['method']) {
                'initialize' => ['protocolVersion' => '2025-11-25', 'capabilities' => ['tools' => (object) []], 'serverInfo' => ['name' => 'synthetic-server', 'version' => '1.0']],
                'tools/list' => ['tools' => $tools],
                'tools/call' => $call($rpc['params'], $request),
                default => throw new \RuntimeException('Unexpected RPC'),
            };
            if ($result instanceof Response || $result instanceof PromiseInterface) {
                return $result;
            }

            return Http::response(['jsonrpc' => '2.0', 'id' => $rpc['id'], 'result' => $result]);
        }]);
    }

    public static function result(array $rows, ?string $next = null, ?string $asOf = null): array
    {
        return ['content' => [], 'structuredContent' => ['records' => $rows, 'next_cursor' => $next, 'as_of' => $asOf ?? now()->toIso8601String()]];
    }

    public static function position(string $id = 'synthetic-position'): array
    {
        return ['id' => $id, 'account_id' => 'synthetic-agentic', 'instrument_id' => 'synthetic-instrument', 'quantity' => '0.123456789012345678', 'market_value' => '12.3456789012345678'];
    }
}
