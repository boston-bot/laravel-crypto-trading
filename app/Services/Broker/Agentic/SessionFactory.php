<?php

namespace App\Services\Broker\Agentic;

use App\Models\McpConnection;

class SessionFactory
{
    public function __construct(private TrustedHttp $http, private OAuthService $oauth) {}

    public function open(McpConnection $connection): McpSession
    {
        $allowed = [];
        foreach (Operation::cases() as $operation) {
            if (in_array($operation, [Operation::Watchlists, Operation::WatchlistItems], true) && ! config('robinhood_mcp.watchlists_enabled')) {
                continue;
            }
            $profile = config('robinhood_mcp.operations.'.$operation->value);
            if (($profile['reviewed_read_only'] ?? false) === true && is_string($profile['tool'] ?? null)) {
                $allowed[] = $profile['tool'];
            }
        }
        try {
            return new McpSession(new RpcHttpClient($this->http, $this->oauth->accessToken($connection), $allowed));
        } catch (ReadFailure $failure) {
            $connection->update(['error_code' => $failure->reason->value] + ($failure->reason === ReadError::Authentication ? ['status' => 'reconnect_required'] : []));
            throw $failure;
        }
    }
}
