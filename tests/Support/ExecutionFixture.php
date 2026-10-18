<?php

namespace Tests\Support;

use App\Models\PaperTrading\ExecutionVersion;
use App\Models\PaperTrading\Portfolio;
use App\Services\PaperTrading\ExecutionPolicies;
use App\Services\PaperTrading\PaperMarketInputs;
use Illuminate\Support\Str;

final class ExecutionFixture
{
    public static function payload(array $changes = []): array
    {
        return array_replace(['decision_sleeve' => 'active', 'order_type' => 'limit', 'session' => 'regular', 'time_in_force' => 'day',
            'initial_holdings' => 'cash_only', 'allowed_symbols' => ['DEMO'], 'allow_entries' => true, 'allow_exits' => true, 'allow_ai_sizing' => true,
            'allow_fractional' => false, 'allow_fractional_limit' => false, 'fee_per_share' => '0.01', 'slippage_bps' => '10',
            'limit_offset_bps' => '20', 'quote_max_age_seconds' => 60], $changes);
    }

    public static function apply($connection, array $changes = []): ExecutionVersion
    {
        $portfolio = Portfolio::where('mcp_connection_id', $connection->id)->sole();
        $base = ExecutionVersion::where('paper_portfolio_id', $portfolio->id)->max('version') ?? 0;
        $draft = app(ExecutionPolicies::class)->save($connection, $connection->user_id, $base, (string) Str::uuid(), self::payload($changes), 'Synthetic execution fixture.');

        return app(ExecutionPolicies::class)->apply($connection, $connection->user_id, $base + 1, (string) Str::uuid(), $draft->id);
    }

    public static function seed(array $changes = []): array
    {
        [$connection, $portfolio, $policy] = SimulationFixture::seed();
        $execution = self::apply($connection, $changes);
        app(PaperMarketInputs::class)->fixture($connection, $connection->user_id, SimulationFixture::quote(), (string) Str::uuid());

        return [$connection, $portfolio, $policy, $execution];
    }
}
