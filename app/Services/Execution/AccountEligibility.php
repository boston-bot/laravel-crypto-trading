<?php

namespace App\Services\Execution;

use App\Models\McpConnection;
use App\Models\Monitoring\Snapshot;
use App\Services\Broker\Agentic\TrustedHttp;
use App\Services\Monitoring\AccountScope;
use Carbon\CarbonImmutable;

/** Fresh account metadata is necessary in addition to the local monitoring role. No network or permission changes. */
final class AccountEligibility
{
    public function reason(McpConnection $connection, Snapshot $snapshot): ?string
    {
        $evidence = $snapshot->payload['account_eligibility'] ?? [];
        $read = $evidence['read'] ?? [];
        $at = $read['retrieved_at'] ?? null;
        $contract = config('robinhood_mcp.operations.accounts.fingerprint');
        if (! config('robinhood_mcp.account_eligibility_enabled') || ! is_string($contract) || ! preg_match('/^[a-f0-9]{64}$/D', $contract) ||
            $snapshot->status !== 'complete' || $snapshot->mcp_connection_id !== $connection->id ||
            $snapshot->scope !== AccountScope::key($connection) || ($evidence['auth_generation'] ?? null) !== $connection->auth_generation ||
            ($evidence['contract_fingerprint'] ?? null) !== $contract ||
            ($read['source'] ?? null) !== TrustedHttp::RESOURCE || ($read['completeness'] ?? null) !== 'complete' ||
            ($read['continuation'] ?? null) !== null || ! is_array($read['records'] ?? null) || ! is_string($at) ||
            ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/D', $at)) {
            return 'account_trading_eligibility_unverified';
        }
        try {
            $time = CarbonImmutable::parse($at);
        } catch (\Exception) {
            return 'account_trading_eligibility_unverified';
        }
        if ($time->isFuture() || $time->lte(CarbonImmutable::now()->subMinutes(5)) || $time->gt($snapshot->retrieved_at)) {
            return 'account_trading_eligibility_stale';
        }
        $matches = array_values(array_filter($read['records'], fn ($row) => ($row['data']['id'] ?? null) === $connection->bound_account_id));
        if (count($matches) !== 1) {
            return 'account_trading_eligibility_unverified';
        }
        $data = $matches[0]['data'];
        foreach (['account_kind' => 'agentic', 'agentic_allowed' => true, 'status' => 'active', 'deactivated' => false,
            'permanently_deactivated' => false, 'management_type' => 'self_directed', 'brokerage_account_type' => 'individual', 'account_type' => 'limited_margin'] as $key => $expected) {
            if (($data[$key] ?? null) !== $expected) {
                return 'account_not_eligible_for_agentic_trading';
            }
        }

        return null;
    }
}
