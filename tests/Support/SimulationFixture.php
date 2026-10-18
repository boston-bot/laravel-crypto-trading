<?php

namespace Tests\Support;

use App\Models\PaperTrading\Portfolio;
use App\Models\User;
use App\Services\Monitoring\MarketCalendar;
use App\Services\PaperTrading\PaperMandates;
use App\Services\PaperTrading\PaperPolicies;
use App\Services\PaperTrading\SimulationAssumptions;
use App\Services\PaperTrading\SimulationQuote;
use Illuminate\Support\Str;

final class SimulationFixture
{
    public static function seed(): array
    {
        $connection = McpFixture::connection()->fresh();
        config(['mcp.operator_emails' => [User::findOrFail($connection->user_id)->email], 'paper.offline_simulation_enabled' => true]);
        $mandate = app(PaperMandates::class)->append($connection, $connection->user_id, 0, (string) Str::uuid(), [
            'initial_cash' => '10000', 'long_term_pct' => '40', 'active_pct' => '50', 'cash_pct' => '10',
            'max_position_pct' => '20', 'daily_loss_pause_pct' => '2', 'monthly_budget' => '100',
            'scan_seconds' => 60, 'decision_minutes' => 5, 'news_minutes' => 5,
        ], 'Synthetic offline simulation.');
        $policy = app(PaperPolicies::class)->apply($connection, $connection->user_id, $mandate->id, 1, 0, (string) Str::uuid());
        $date = now()->setTimezone('America/New_York')->toDateString();
        app(MarketCalendar::class)->store($date, 'regular', $date.'T13:30:00Z', $date.'T20:00:00Z', 'synthetic_fixture');

        return [$connection, Portfolio::findOrFail($policy->paper_portfolio_id), $policy];
    }

    public static function assumptions(array $changes = []): SimulationAssumptions
    {
        return new SimulationAssumptions(array_replace(['fee_per_share' => '0.01', 'slippage_bps' => '10', 'quote_max_age_seconds' => 60,
            'allow_fractional' => false, 'allow_fractional_limit' => false, 'session_open_equity' => '10000',
            'session_date' => now()->setTimezone('America/New_York')->toDateString()], $changes));
    }

    public static function intent(array $changes = []): array
    {
        return array_replace(['request_id' => (string) Str::uuid(), 'symbol' => 'DEMO', 'side' => 'BUY', 'sleeve' => 'active',
            'type' => 'limit', 'quantity' => '10', 'price_bound' => '101', 'expires_at' => now()->addMinutes(10)->toIso8601String(),
            'rationale' => 'Synthetic proposal retained through winning and losing scenarios.'], $changes);
    }

    public static function quote(array $changes = []): SimulationQuote
    {
        return new SimulationQuote(array_replace(['key' => 'synthetic:'.Str::uuid(), 'symbol' => 'DEMO', 'provenance' => 'synthetic',
            'source_at' => now()->toIso8601String(), 'retrieved_at' => now()->toIso8601String(), 'bid' => '99', 'ask' => '100',
            'bid_size' => '100', 'ask_size' => '100', 'asset_type' => 'stock', 'eligible' => true, 'fractional' => true,
            'leveraged' => false, 'inverse' => false, 'corporate_actions_clear' => true], $changes));
    }
}
