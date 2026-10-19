<?php

declare(strict_types=1);

namespace App\Services\PaperTrading;

use App\Models\PaperTrading\MarketInput;
use App\Models\PaperTrading\Portfolio;
use App\Services\MarketData\Sec\EdgarClassifications;
use Carbon\CarbonImmutable;

/**
 * Owner-mandate product classes from two independent reviewed sources: a fresh, instrument-bound Robinhood
 * stock/ETF observation and stored SEC filing evidence. Payload text and model output cannot set these fields.
 */
final class InstrumentEligibility
{
    public function inspect(MarketInput $input, CarbonImmutable $cutoff, int $maxAge, bool $enforceAllowlist = true): array
    {
        $result = ['status' => 'unavailable', 'reason' => 'classification_policy_disabled', 'policy' => 'robinhood_type_and_sec_filing_v1',
            'eligible' => null, 'asset_type' => null, 'leveraged' => null, 'inverse' => null, 'basis' => null, 'classification_id' => null];
        if (! config('paper.instrument_classification_enabled')) {
            return $result;
        }
        if ($input->origin !== 'broker_read' || $input->recorded_at->gt($cutoff) || $input->retrieved_at->gt($cutoff) || ($input->payload['quote']['symbol'] ?? null) !== $input->symbol) {
            return array_replace($result, ['reason' => 'market_input_unverified']);
        }
        $portfolio = Portfolio::where('mcp_connection_id', $input->mcp_connection_id)->where('scope', $input->scope)->first();
        $execution = $portfolio ? ExecutionPolicies::current($portfolio) : null;
        if (! $execution) {
            return array_replace($result, ['reason' => 'applied_execution_settings_missing']);
        }
        if ($enforceAllowlist && ! RuleEligibilityPolicies::current($portfolio, $cutoff) && ! in_array($input->symbol, $execution->payload['allowed_symbols'], true)) {
            return array_replace($result, ['status' => 'ineligible', 'reason' => 'symbol_not_in_applied_allowlist', 'eligible' => false]);
        }
        $type = app(ReferenceDataCoverage::class)->inspect($input->payload, $cutoff, $maxAge)['asset_type'];
        if (! in_array($type, ['stock', 'etf'], true)) {
            return array_replace($result, ['reason' => 'broker_asset_type_unverified']);
        }
        $record = app(PaperInstrumentClassifications::class)->latestComplete($input->mcp_connection_id, $input->scope, $input->symbol, $cutoff);
        if (! $record || $record->retrieved_at->lt($cutoff->subDays((int) config('sec_edgar.classification_max_age_days')))) {
            return array_replace($result, ['reason' => 'sec_classification_missing_or_stale']);
        }
        $p = $record->payload;
        $result = array_replace($result, ['classification_id' => $record->id, 'basis' => $record->basis, 'classification_retrieved_at' => $record->retrieved_at->toISOString()]);
        if (($p['provider'] ?? null) !== 'sec_edgar' || ($p['contract'] ?? null) !== EdgarClassifications::CONTRACT || ($p['symbol'] ?? null) !== $input->symbol ||
            ($p['complete'] ?? false) !== true || ($p['basis'] ?? null) !== $record->basis) {
            return array_replace($result, ['reason' => 'sec_classification_contract_unverified']);
        }
        if ($record->basis === 'registered_fund_ncen') {
            $period = $p['fund']['report_period_end'] ?? null;
            if ($type !== 'etf') {
                return array_replace($result, ['reason' => 'classification_sources_disagree']);
            }
            if (! is_string($period) || $period < $cutoff->subDays((int) config('sec_edgar.report_max_age_days'))->toDateString()) {
                return array_replace($result, ['reason' => 'ncen_report_stale']);
            }
            $result['report_period_end'] = $period;
            $result['accession'] = $p['fund']['accession'] ?? null;
            if (($p['multiple_or_inverse'] ?? null) === true) {
                // Item C.3.c combines leverage and inversion, so neither individual flag is asserted.
                return array_replace($result, ['status' => 'ineligible', 'reason' => 'regulatory_leveraged_or_inverse_fund', 'eligible' => false, 'asset_type' => 'etf']);
            }
            if (($p['multiple_or_inverse'] ?? null) !== false) {
                return array_replace($result, ['reason' => 'sec_classification_contract_unverified']);
            }
        } elseif ($record->basis === 'operating_issuer_sole_listed_security') {
            if ($type !== 'stock') {
                return array_replace($result, ['reason' => 'classification_sources_disagree']);
            }
        } else {
            return array_replace($result, ['reason' => 'sec_'.($record->reason ?? 'unclassified')]);
        }

        return array_replace($result, ['status' => 'eligible', 'reason' => null, 'eligible' => true, 'asset_type' => $type, 'leveraged' => false, 'inverse' => false]);
    }
}
