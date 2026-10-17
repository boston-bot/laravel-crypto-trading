<?php

namespace App\Services\Research;

use App\Models\McpConnection;
use App\Models\Research\Event;
use App\Models\Research\Run;
use App\Models\Research\Source;
use App\Models\Research\Summary;
use App\Models\Research\WatchlistItem;

final class ResearchOverview
{
    public function __construct(private ResearchBudget $budget, private MarketDataProvider $market, private ResearchEvents $events) {}

    public function for(McpConnection $connection, string $scope): array
    {
        $runs = Run::where('mcp_connection_id', $connection->id)->where('scope', $scope)->latest('created_at')->limit(25)->get();
        $latest = Run::where('mcp_connection_id', $connection->id)->where('scope', $scope)->where('status', 'succeeded')->latest('finished_at')->limit(50)->get()
            ->unique('subject')->values();
        $sourceIds = $latest->flatMap(fn ($run) => array_values($run->result['source_map'] ?? []))->unique()->all();

        return [
            'readiness' => ResearchReadiness::reason()?->value,
            'budget' => $this->budget->status(),
            'coverage' => $this->market->coverage() + ['operator_evidence' => config('research.market_data.operator_evidence')],
            'events' => Event::where('mcp_connection_id', $connection->id)->where('scope', $scope)->latest('last_seen_at')->limit(25)->get(),
            'runs' => $runs,
            'latest' => $latest,
            'cited' => Source::whereIn('id', $sourceIds)->where('mcp_connection_id', $connection->id)->where('scope', $scope)->get()->keyBy('id'),
            'sources' => Source::where('mcp_connection_id', $connection->id)->where('scope', $scope)->where('kind', '!=', 'quote')->latest('id')->limit(25)->get(),
            'watchlist' => WatchlistItem::where('mcp_connection_id', $connection->id)->where('scope', $scope)->whereNotNull('active_symbol')->orderBy('symbol')->get(),
            'subjects' => $this->events->subjects($connection),
            'summaries' => Summary::where('mcp_connection_id', $connection->id)->where('scope', $scope)->latest('generated_at')->limit(6)->get(),
        ];
    }
}
