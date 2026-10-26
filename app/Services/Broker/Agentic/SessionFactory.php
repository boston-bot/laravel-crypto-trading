<?php

namespace App\Services\Broker\Agentic;

use App\Models\McpConnection;

class SessionFactory
{
    public function __construct(private TrustedHttp $http, private OAuthService $oauth) {}

    /** The only tools a write session may call. Reachable solely from the execution gateway; reads never use it. */
    public const EXECUTION_TOOLS = ['review_equity_order', 'place_equity_order'];

    /** A separate session for the execution boundary. Fails closed unless brokerage writes are enabled. */
    public function openExecution(McpConnection $connection, int $minTokenSeconds = 60): McpSession
    {
        if (! config('mcp.brokerage_writes_enabled')) {
            throw new ReadFailure(ReadError::Disabled);
        }
        try {
            return new McpSession(new RpcHttpClient($this->http, $this->oauth->accessToken($connection, $minTokenSeconds), self::EXECUTION_TOOLS));
        } catch (ReadFailure $failure) {
            $connection->update(['error_code' => $failure->reason->value] + ($failure->reason === ReadError::Authentication ? ['status' => 'reconnect_required'] : []));
            throw $failure;
        }
    }

    public function open(McpConnection $connection, int $minTokenSeconds = 30): McpSession
    {
        $allowed = [];
        foreach (Operation::cases() as $operation) {
            if (in_array($operation, [Operation::Classification, Operation::Dividends], true) && ! config('robinhood_mcp.reference_data_enabled')) {
                continue;
            }
            if (in_array($operation, [Operation::Tradability, Operation::PriceBook], true) && ! config('robinhood_mcp.execution_inputs_enabled')) {
                continue;
            }
            if (in_array($operation, [Operation::Watchlists, Operation::WatchlistItems], true) && ! config('robinhood_mcp.watchlists_enabled')) {
                continue;
            }
            if ($operation === Operation::EarningsCalendar && ! config('robinhood_mcp.earnings_enabled')) {
                continue;
            }
            if ($operation === Operation::TradeApprovalSetting && ! config('robinhood_mcp.approval_setting_enabled')) {
                continue;
            }
            $profile = config('robinhood_mcp.operations.'.$operation->value);
            if (($profile['reviewed_read_only'] ?? false) === true && is_string($profile['tool'] ?? null)) {
                $allowed[] = $profile['tool'];
            }
        }
        try {
            return new McpSession(new RpcHttpClient($this->http, $this->oauth->accessToken($connection, $minTokenSeconds), $allowed));
        } catch (ReadFailure $failure) {
            $connection->update(['error_code' => $failure->reason->value] + ($failure->reason === ReadError::Authentication ? ['status' => 'reconnect_required'] : []));
            throw $failure;
        }
    }
}
