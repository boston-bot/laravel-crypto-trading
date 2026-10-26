<?php

namespace Tests\Support;

use App\Models\McpConnection;
use App\Models\PaperTrading\Portfolio;
use App\Models\PaperTrading\StrategyCycle;
use App\Models\PaperTrading\StrategySignal;
use App\Services\Monitoring\AccountScope;
use App\Services\PaperTrading\SignalRules;
use App\Support\Mcp\TestDatabaseGuard;
use Illuminate\Support\Facades\DB;

/** Synthetic journal inputs, not evidence that live entry/risk/approval checks passed. */
final class ExecutionJournalFixture
{
    public static function seed(): array
    {
        [$connection] = SimulationFixture::seed();

        return [$connection, self::signal($connection)];
    }

    public static function signal(McpConnection $connection, string $symbol = 'DEMO'): StrategySignal
    {
        $portfolio = Portfolio::where('mcp_connection_id', $connection->id)->sole();
        $cycle = StrategyCycle::where('mcp_connection_id', $connection->id)->first() ?? StrategyCycle::create([
            'mcp_connection_id' => $connection->id, 'paper_portfolio_id' => $portfolio->id, 'scope' => AccountScope::key($connection),
            'auth_generation' => $connection->auth_generation, 'actor_id' => $connection->user_id, 'rule_version' => SignalRules::VERSION,
            'cycle_at' => now(), 'cutoff' => now(), 'recorded_at' => now(), 'payload' => [],
        ]);

        return StrategySignal::create(['mcp_connection_id' => $connection->id, 'paper_portfolio_id' => $portfolio->id,
            'cycle_id' => $cycle->id, 'symbol' => $symbol, 'status' => 'signal', 'side' => 'BUY', 'reason' => 'synthetic_journal_fixture',
            'rule_version' => SignalRules::VERSION, 'parameters_version' => 'fixture-v1', 'input_fingerprint' => hash('sha256', $symbol),
            'cutoff' => now(), 'recorded_at' => now(), 'payload' => []]);
    }

    public static function draft(array $changes = []): array
    {
        return array_replace(['type' => 'market', 'quantity' => '0.100001', 'price_bound' => '100.01',
            'expires_at' => now()->addMinutes(4)->toISOString(), 'policy_fingerprint' => hash('sha256', 'unapproved-fixture-policy')], $changes);
    }

    /** Every execution table with user triggers, children first. */
    private const TABLES = ['fills', 'outcomes', 'broker_orders', 'events', 'dispatches', 'intents', 'policies', 'controls'];

    public static function cleanup(?int $connection): void
    {
        if ($connection === null) {
            return;
        }
        TestDatabaseGuard::check(DB::connection()->getDriverName(), DB::connection()->getDatabaseName(), 'postgres');
        $bound = McpConnection::find($connection);
        $scope = $bound?->bound_account_id ? AccountScope::key($bound) : null;
        // Test owner only: transactional trigger disable permits narrowly scoped committed-fixture cleanup.
        DB::transaction(function () use ($connection, $scope) {
            foreach (self::TABLES as $suffix) {
                DB::statement('ALTER TABLE "mcp_trading"."mcp_execution_'.$suffix.'" DISABLE TRIGGER USER');
            }
            $ids = DB::table('mcp_execution_intents')->where('mcp_connection_id', $connection)->pluck('id');
            $accounts = DB::table('mcp_execution_intents')->whereIn('id', $ids)->pluck('execution_account_id')
                ->merge(DB::table('mcp_execution_accounts')->where('scope', $scope)->pluck('id'))->unique()->values();
            foreach (['fills', 'outcomes', 'broker_orders', 'events', 'dispatches'] as $suffix) {
                DB::table('mcp_execution_'.$suffix)->whereIn('intent_id', $ids)->delete();
            }
            DB::table('mcp_execution_intents')->whereIn('id', $ids)->delete();
            $unused = DB::table('mcp_execution_accounts')->whereIn('id', $accounts)->whereNotIn('id', DB::table('mcp_execution_intents')->select('execution_account_id'))->pluck('id');
            DB::table('mcp_execution_policies')->whereIn('execution_account_id', $unused)->delete();
            DB::table('mcp_execution_controls')->whereIn('execution_account_id', $unused)->delete();
            DB::table('mcp_execution_accounts')->whereIn('id', $unused)->delete();
            foreach (self::TABLES as $suffix) {
                DB::statement('ALTER TABLE "mcp_trading"."mcp_execution_'.$suffix.'" ENABLE TRIGGER USER');
            }
        });
        // Owner links review external orders; reviews are keyed by order, not connection.
        DB::table('mcp_external_order_reviews')->whereIn('external_order_id', DB::table('mcp_external_orders')->where('mcp_connection_id', $connection)->select('id'))->delete();
        IntegrationCleanup::connection($connection);
    }
}
