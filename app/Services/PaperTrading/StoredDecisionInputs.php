<?php

namespace App\Services\PaperTrading;

use App\Models\McpConnection;
use App\Models\Research\Source;
use App\Services\Monitoring\AccountScope;
use Carbon\CarbonImmutable;

/** Bounded existing local evidence, not a broad-universe or licensed news integration. */
final class StoredDecisionInputs implements DecisionInputs
{
    public function load(McpConnection $connection, CarbonImmutable $cutoff): array
    {
        $query = Source::where('mcp_connection_id', $connection->id)->where('scope', AccountScope::key($connection))
            ->where('source_as_of', '<=', $cutoff)->where('retrieved_at', '<=', $cutoff)->where('created_at', '<=', $cutoff)
            ->where('source_as_of', '>=', $cutoff->subDays((int) config('research.source_window_days')));
        // Group before limiting so a noisy subject cannot hide every other subject.
        $symbols = (clone $query)->select('symbol')->selectRaw('MAX(id) AS newest')->groupBy('symbol')->orderByDesc('newest')
            ->get()->pluck('symbol')->filter(fn ($s) => preg_match('/^[A-Z][A-Z0-9.]{0,9}$/D', $s))->take((int) config('paper.max_symbols'));
        $sources = [];
        $subjects = [];
        $map = [];
        foreach ($symbols as $symbol) {
            $subjects[$symbol] = ['eligibility' => 'unknown', 'quote' => null];
            $rows = collect([(clone $query)->where('symbol', $symbol)->where('kind', 'quote')->latest('source_as_of')->latest('id')->first(),
                (clone $query)->where('symbol', $symbol)->where('kind', '!=', 'quote')->latest('source_as_of')->latest('id')->first()])->filter();
            foreach ($rows as $source) {
                if (count($sources) >= (int) config('paper.max_sources')) {
                    break;
                }
                $id = 'S'.(count($sources) + 1);
                $entry = ['id' => $id, 'symbol' => $symbol, 'kind' => $source->kind, 'as_of' => $source->source_as_of->toIso8601String(),
                    'retrieved_at' => $source->retrieved_at->toIso8601String()];
                if ($source->kind === 'quote') {
                    $quote = array_intersect_key($source->payload['data'] ?? [], array_flip(['bid', 'ask', 'last', 'last_session']));
                    $entry['quote'] = $quote;
                    $subjects[$symbol]['quote'] = $quote + ['source_as_of' => $entry['as_of'], 'retrieved_at' => $entry['retrieved_at']];
                } else {
                    $entry['excerpt'] = mb_substr((string) ($source->payload['excerpt'] ?? ''), 0, (int) config('paper.max_excerpt_chars'));
                }
                $sources[] = $entry;
                $map[$id] = ['symbol' => $symbol, 'record_id' => $source->id, 'as_of' => $entry['as_of']];
            }
        }
        if ($subjects === []) {
            throw new DecisionFailure('no_stored_evidence', 'unavailable');
        }

        return ['coverage' => 'stored_account_evidence_only', 'subjects' => $subjects, 'sources' => $sources, 'source_map' => $map,
            'positions' => null, 'positions_status' => 'not_implemented'];
    }
}
