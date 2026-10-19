<?php

namespace App\Services\PaperTrading;

use App\Models\McpConnection;
use App\Models\PaperTrading\MarketInput;
use App\Models\PaperTrading\Portfolio;
use App\Services\Monitoring\AccountScope;
use Carbon\CarbonImmutable;

/** Reports current data coverage only. It cannot authorize policy, collections, model calls or fills. */
final class ExecutionDataReadiness
{
    public function for(McpConnection $connection, CarbonImmutable $cutoff): array
    {
        $scope = AccountScope::key($connection);
        $portfolio = Portfolio::where('mcp_connection_id', $connection->id)->where('scope', $scope)->first();
        $execution = $portfolio ? ExecutionPolicies::current($portfolio) : null;
        if (! $execution) {
            return ['status' => 'unavailable', 'reason' => 'applied_execution_settings_missing', 'symbols' => [], 'symbol_count' => 0, 'ready_count' => 0];
        }
        $symbols = [];
        foreach ($execution->payload['allowed_symbols'] as $symbol) {
            $input = app(PaperMarketInputs::class)->latest($connection, $symbol, $cutoff);
            $symbols[$symbol] = $this->inspect($input, $cutoff, $execution->payload['quote_max_age_seconds']);
        }

        return ['status' => 'blocked', 'reason' => 'verified_reference_and_real_fill_coverage_incomplete',
            'as_of' => $cutoff->toIso8601String(), 'policy_revision' => $portfolio->policy_revision, 'execution_version' => $execution->version,
            'symbols' => $symbols, 'symbol_count' => count($symbols), 'ready_count' => 0];
    }

    public function inspect(?MarketInput $input, CarbonImmutable $cutoff, int $maxAge): array
    {
        // The current reviewed source has no authority for these facts, regardless of extra payload text.
        $checks = ['classification' => 'no_reviewed_stock_etf_classification',
            'leverage_and_inverse' => 'no_reviewed_leverage_inverse_classification',
            'corporate_actions' => 'no_complete_corporate_action_coverage',
            'fractional_limit' => 'no_reviewed_fractional_limit_contract',
            'real_fill_adapter' => config('paper.real_data_fills_enabled') ? 'implemented_requires_verified_inputs' : 'implemented_disabled'];
        if (! $input) {
            return ['status' => 'blocked', 'input_id' => null, 'checks' => ['market_observation' => 'not_collected'] + $checks];
        }
        if ($input->origin === 'synthetic') {
            return ['status' => 'synthetic_only', 'input_id' => $input->id, 'checks' => ['market_observation' => 'synthetic_not_real_market_evidence'] + $checks];
        }
        if ($input->origin !== 'broker_read' || $input->retrieved_at->gt($cutoff) || $input->recorded_at->gt($cutoff)) {
            return ['status' => 'blocked', 'input_id' => $input->id, 'checks' => ['market_observation' => 'source_or_local_availability_unverified'] + $checks];
        }
        $payload = $input->payload;
        $match = app(BookQuoteMatcher::class)->match($payload, $cutoff, $maxAge);
        $reference = app(ReferenceDataCoverage::class)->inspect($payload, $cutoff, $maxAge);
        $checks['classification'] = $reference['classification_status'];
        $checks['corporate_actions'] = $reference['corporate_action_status'];
        $checks['dividend_subset'] = $reference['dividend_status'];
        $actions = app(CorporateActionCoverage::class)->inspect($payload, $cutoff, $maxAge);
        $checks['alpaca_actions'] = $actions['status'];
        $actionPolicy = app(CorporateActionClearance::class)->inspect($input, $cutoff, $maxAge);
        $checks['observed_action_policy'] = $actionPolicy['reason'] ?? $actionPolicy['status'];
        $checks['alpaca_event_count'] = (string) $actions['events'];
        $checks['alpaca_incomplete_event_count'] = (string) $actions['incomplete_events'];
        $reads = $payload['execution_reads'] ?? [];
        $tradability = $reads['tradability'] ?? [];
        $checks = ['market_observation' => ($payload['complete'] ?? false) ? 'base_reads_complete' : 'base_reads_incomplete',
            'quote_and_book' => $match['status'] === 'matched' ? 'verified_prices_times_and_share_units' : $match['reason'],
            'broker_tradability' => $this->tradabilityStatus($tradability, $cutoff, $maxAge)] + $checks;

        return ['status' => 'blocked', 'input_id' => $input->id, 'checks' => $checks];
    }

    private function tradabilityStatus(array $entry, CarbonImmutable $cutoff, int $maxAge): string
    {
        if (($entry['complete'] ?? false) !== true || ! is_string($entry['retrieved_at'] ?? null)) {
            return 'not_collected_or_incomplete';
        }
        try {
            $retrieved = CarbonImmutable::parse($entry['retrieved_at']);
            if ($retrieved->gt($cutoff) || $retrieved->lt($cutoff->subSeconds($maxAge))) {
                return 'retrieval_stale_or_future';
            }
        } catch (\InvalidArgumentException) {
            return 'retrieval_time_invalid';
        }

        return match ($entry['fields']['broker_tradeable'] ?? null) {
            true => 'observed_tradeable_source_time_unknown',
            false => 'broker_denied',
            default => 'unknown',
        };
    }
}
