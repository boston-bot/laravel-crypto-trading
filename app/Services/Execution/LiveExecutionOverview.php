<?php

namespace App\Services\Execution;

use App\Models\Execution\ExecutionApproval;
use App\Models\Execution\ExecutionFill;
use App\Models\Execution\ExecutionOutcome;
use App\Models\Execution\OrderIntent;
use App\Models\McpConnection;
use App\Models\Monitoring\ExternalOrder;
use App\Models\Monitoring\Incident;
use App\Services\Monitoring\AccountScope;
use App\Services\Monitoring\ExternalOrders;
use Illuminate\Support\Facades\DB;

/** Read model for the Control Center. Broker-confirmed results only; never paper fills. No writes. */
final class LiveExecutionOverview
{
    public function for(McpConnection $connection): array
    {
        $scope = AccountScope::key($connection);
        $account = DB::table('mcp_execution_accounts')->where('scope', $scope)->value('id');
        $intents = OrderIntent::where('execution_account_id', $account ?? 0)->latest('id')->limit(10)->get();
        $dispatches = DB::table('mcp_execution_dispatches')->whereIn('intent_id', $intents->pluck('id'))->get()->keyBy('intent_id');
        $outcomes = ExecutionOutcome::whereIn('intent_id', $intents->pluck('id'))->latest('id')->get()->unique('intent_id')->keyBy('intent_id');
        $rows = $intents->map(fn (OrderIntent $intent) => ['intent' => $intent, 'status' => $dispatches[$intent->id]->status ?? 'missing',
            'claimed_at' => $dispatches[$intent->id]->claimed_at ?? null, 'outcome' => $outcomes[$intent->id] ?? null]);
        $approvals = ExecutionApproval::whereIn('intent_id', $intents->pluck('id'))->get()->keyBy('intent_id');
        $unknown = $rows->whereIn('status', ['unknown', 'approval_pending'])->map(fn ($row) => $row + ['approval' => $approvals[$row['intent']->id] ?? null])->values();
        // Owner links choose among this account's observed orders matching side and symbol; the journal rechecks quantity and time.
        $candidates = $unknown->isEmpty() ? collect() : ExternalOrder::where('mcp_connection_id', $connection->id)->where('scope', $scope)
            ->whereNotIn('broker_id_hash', DB::table('mcp_execution_broker_orders')->select('broker_id_hash'))->latest('id')->limit(50)->get();

        return ['writes_enabled' => (bool) config('mcp.brokerage_writes_enabled'), 'policy' => LivePolicies::current($scope),
            'controls' => ExecutionControls::state($scope), 'intents' => $rows, 'unknown' => $unknown->map(fn ($row) => $row + ['candidates' => $candidates->filter(
                fn ($order) => ($order->payload['order']['symbol'] ?? null) === $row['intent']->payload['symbol'] &&
                    ($order->payload['order']['side'] ?? null) === strtolower($row['intent']->payload['side']))->values()]),
            'incidents' => Incident::where('mcp_connection_id', $connection->id)->where('scope', $scope)
                ->whereIn('incident_key', [BrokerOutcomes::MISMATCH, BrokerOutcomes::MISSING])->where('state', '!=', 'resolved')->get(),
            'fills' => ExecutionFill::where('execution_account_id', $account ?? 0)->latest('id')->limit(10)->get(),
            'entry_pause_reason' => app(ExternalOrders::class)->entryReason($connection)];
    }
}
