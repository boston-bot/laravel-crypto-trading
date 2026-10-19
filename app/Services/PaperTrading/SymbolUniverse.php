<?php

namespace App\Services\PaperTrading;

use App\Models\McpConnection;
use App\Models\Monitoring\Holding;
use App\Models\Monitoring\Snapshot;
use App\Models\PaperTrading\Portfolio;
use App\Models\PaperTrading\SimulationPosition;
use App\Services\Monitoring\AccountScope;
use App\Support\Mcp\Decimal;
use Carbon\CarbonImmutable;

/** Data reads include holdings independently of membership/entry pauses. This never grants entry permission. */
final class SymbolUniverse
{
    public function candidates(McpConnection $connection, CarbonImmutable $cutoff): array
    {
        $membership = app(StrategyWatchlists::class)->nominations($connection, $cutoff);

        // Nominating lists only; data-only (warm-up) members never become entry candidates.
        return $membership['fresh'] ? StrategyWatchlists::symbols(array_filter($membership['revision']->payload['members'], fn ($m) => $m['skip_reason'] === null), 'nominate') : [];
    }

    public function reads(McpConnection $connection, CarbonImmutable $cutoff): array
    {
        $portfolio = Portfolio::where('mcp_connection_id', $connection->id)->where('scope', AccountScope::key($connection))->first();
        if (! $portfolio) {
            return [];
        }
        if (! RuleEligibilityPolicies::current($portfolio, $cutoff)) {
            return ExecutionPolicies::current($portfolio)?->payload['allowed_symbols'] ?? [];
        }
        $held = SimulationPosition::where('paper_portfolio_id', $portfolio->id)->get()->filter(fn ($p) => Decimal::from($p->quantity)->compareTo(Decimal::from('0')) > 0)->pluck('symbol')->all();
        $snapshot = Snapshot::where('mcp_connection_id', $connection->id)->where('scope', AccountScope::key($connection))->where('status', 'complete')
            ->where('published_at', '<=', $cutoff->utc()->format('Y-m-d H:i:s.u'))->latest('publication_version')->first();
        if ($snapshot) {
            $held = [...$held, ...Holding::where('snapshot_id', $snapshot->id)->whereNotNull('symbol')->get()
                ->filter(fn ($p) => Decimal::from($p->quantity)->compareTo(Decimal::from('0')) !== 0)->pluck('symbol')->all()];
        }
        // Data-only (warm-up) lists are collected so a symbol promoted to a nominating list already has data.
        $warmup = config('paper.rule_eligibility_enabled') ? app(StrategyWatchlists::class)->nominations($connection, $cutoff)['data_symbols'] : [];
        $symbols = array_values(array_unique([...(config('paper.rule_eligibility_enabled') ? $this->candidates($connection, $cutoff) : []), ...$warmup, ...$held]));
        sort($symbols);

        return $symbols;
    }
}
