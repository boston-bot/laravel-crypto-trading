<?php

namespace App\Services\Broker\Agentic;

use App\Models\McpConnection;
use Illuminate\Support\Facades\DB;

final class AccountBinding
{
    public function __construct(private BrokerGateway $gateway) {}

    public function bind(McpConnection $connection, string $accountId, string $role, int $actorId): void
    {
        if ($connection->user_id !== $actorId || ! in_array($role, ['agentic_monitoring', 'personal_advisory'], true)) {
            throw new ReadFailure(ReadError::Forbidden);
        }
        $connection->refresh();
        $generation = $connection->auth_generation;
        $accounts = $this->gateway->read($connection, Operation::Accounts);
        if (! $accounts->complete || $accounts->source !== TrustedHttp::RESOURCE) {
            throw new ReadFailure(ReadError::Incomplete);
        }
        $account = null;
        foreach ($accounts->records as $row) {
            if ($row->fields['id'] === $accountId) {
                $account = $row;
                break;
            }
        }
        $expectedKind = $role === 'agentic_monitoring' ? 'agentic' : 'personal';
        if (! $account || $account->fields['account_kind'] !== $expectedKind) {
            throw new ReadFailure(ReadError::AccountMismatch);
        }
        DB::transaction(function () use ($connection, $actorId, $accountId, $role, $generation): void {
            $current = McpConnection::whereKey($connection->id)->lockForUpdate()->firstOrFail();
            if ($current->status !== 'connected' || $current->user_id !== $actorId || $current->auth_generation !== $generation) {
                throw new ReadFailure(ReadError::Authentication);
            }
            $current->update(['bound_account_id' => $accountId, 'account_role' => $role, 'bound_by' => $actorId, 'bound_at' => now()]);
        });
    }
}
