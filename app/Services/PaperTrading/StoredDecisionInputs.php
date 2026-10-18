<?php

namespace App\Services\PaperTrading;

use App\Models\McpConnection;
use App\Models\PaperTrading\Portfolio;
use App\Models\PaperTrading\SimulationPosition;
use App\Models\Research\Source;
use App\Services\Monitoring\AccountScope;
use App\Support\Mcp\Decimal;
use Carbon\CarbonImmutable;

/** Point-in-time stored evidence and adapter records; never a broad-market coverage claim. */
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
        $portfolio = Portfolio::where('mcp_connection_id', $connection->id)->where('scope', AccountScope::key($connection))->first();
        $execution = $portfolio ? ExecutionPolicies::current($portfolio) : null;
        $positions = null;
        if ($execution) {
            // Legacy research availability is stored at second precision. Exclude the cutoff second
            // rather than treating a later same-second arrival as already available to execution.
            $query->where('created_at', '<', $cutoff->startOfSecond());
            $held = SimulationPosition::where('paper_portfolio_id', $portfolio->id)->get();
            $positions = $held->where('sleeve', $execution->payload['decision_sleeve'])->pluck('quantity', 'symbol')->all();
            $positive = $held->filter(fn ($p) => Decimal::from($p->quantity)->compareTo(Decimal::from('0')) > 0)->pluck('symbol')->all();
            $symbols = collect(array_unique([...$positive, ...$symbols->all(), ...$execution->payload['allowed_symbols']]))->take((int) config('paper.max_symbols'));
        }
        $sources = [];
        $subjects = [];
        $map = [];
        foreach ($symbols as $symbol) {
            $subjects[$symbol] = ['eligibility' => 'unknown', 'quote' => null];
            if ($execution) {
                $positions[$symbol] ??= '0';
                $input = app(PaperMarketInputs::class)->latest($connection, $symbol, $cutoff);
                if ($input && count($sources) < (int) config('paper.max_sources')) {
                    $q = $input->payload['quote'];
                    $id = 'M'.$input->id;
                    $asOf = $input->source_at?->toIso8601String();
                    $subjects[$symbol] = ['eligibility' => app(PaperMarketInputs::class)->executionReason($input, $cutoff, $execution->payload['quote_max_age_seconds']) === null ? 'eligible' : 'unknown',
                        'quote' => ['bid' => $q['bid'], 'ask' => $q['ask'], 'source_as_of' => $asOf, 'retrieved_at' => $input->retrieved_at->toIso8601String()],
                        'input_id' => $input->id, 'origin' => $input->origin, 'coverage' => $input->payload['coverage']];
                    $sources[] = ['id' => $id, 'symbol' => $symbol, 'kind' => 'adapter_quote', 'as_of' => $asOf,
                        'retrieved_at' => $input->retrieved_at->toIso8601String(), 'quote' => $subjects[$symbol]['quote'], 'origin' => $input->origin];
                    $map[$id] = ['symbol' => $symbol, 'market_input_id' => $input->id, 'as_of' => $asOf];
                }
            }
            $rows = collect([$execution ? null : (clone $query)->where('symbol', $symbol)->where('kind', 'quote')->latest('source_as_of')->latest('id')->first(),
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

        return ['coverage' => $execution ? 'stored_adapter_subset_not_broad_screening' : 'stored_account_evidence_only', 'subjects' => $subjects, 'sources' => $sources, 'source_map' => $map,
            'positions' => $positions, 'positions_status' => $execution ? 'local_paper_ledger_selected_sleeve' : 'not_implemented'];
    }
}
