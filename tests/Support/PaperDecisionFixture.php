<?php

namespace Tests\Support;

use App\Models\PaperTrading\Portfolio;
use App\Models\User;
use App\Services\Monitoring\MarketCalendar;
use App\Services\PaperTrading\PaperMandates;
use App\Services\PaperTrading\PaperPolicies;
use Illuminate\Support\Str;

final class PaperDecisionFixture
{
    public static function seed(): array
    {
        $connection = McpFixture::connection()->fresh();
        $user = User::findOrFail($connection->user_id);
        config(['mcp.operator_emails' => [$user->email], 'paper.decisions_enabled' => true]);
        ResearchFixture::enable();
        $mandate = app(PaperMandates::class)->append($connection, $user->id, 0, (string) Str::uuid(), [
            'initial_cash' => '10000.00', 'long_term_pct' => '40', 'active_pct' => '50', 'cash_pct' => '10',
            'max_position_pct' => '20', 'daily_loss_pause_pct' => '2', 'monthly_budget' => '100.00',
            'scan_seconds' => 60, 'decision_minutes' => 5, 'news_minutes' => 5, 'ai_guidance' => 'Synthetic multi-week guidance.',
        ], 'Synthetic decision fixture.');
        app(PaperPolicies::class)->apply($connection, $user->id, $mandate->id, 1, 0, (string) Str::uuid());
        $date = now()->setTimezone('America/New_York')->toDateString();
        app(MarketCalendar::class)->store($date, 'regular', $date.'T13:30:00Z', $date.'T20:00:00Z', 'synthetic_fixture');

        return [$connection, $user, Portfolio::where('mcp_connection_id', $connection->id)->sole()];
    }
}
