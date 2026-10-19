<?php

namespace App\Services\PaperTrading;

use App\Services\Broker\Agentic\TrustedHttp;
use Carbon\CarbonImmutable;

/** Reviewed reference subsets only. Observing a type or dividend never proves complete execution eligibility. */
final class ReferenceDataCoverage
{
    public function inspect(array $payload, CarbonImmutable $cutoff, int $maxAge): array
    {
        $result = ['asset_type' => null, 'classification_status' => 'no_reviewed_stock_etf_classification',
            'leverage_and_inverse_status' => 'no_reviewed_leverage_inverse_classification',
            'corporate_action_status' => 'no_complete_corporate_action_coverage', 'dividend_status' => 'not_collected',
            'leveraged' => null, 'inverse' => null, 'corporate_actions_clear' => null];
        if (($payload['provider'] ?? null) !== TrustedHttp::RESOURCE || ($payload['complete'] ?? false) !== true) {
            return $result;
        }
        $symbol = $payload['quote']['symbol'] ?? null;
        $reads = $payload['reference_reads'] ?? [];
        $classification = $reads['classification'] ?? [];
        if ($this->usable($classification, 'classification', $symbol, $cutoff, $maxAge) &&
            is_string($payload['instrument']['id'] ?? null) && ($classification['fields']['id'] ?? null) === $payload['instrument']['id']) {
            $type = $classification['fields']['asset_type'] ?? null;
            if (in_array($type, ['stock', 'etf'], true)) {
                $result['asset_type'] = $type;
                $result['classification_status'] = 'observed_'.$type.'_source_time_unknown';
            }
        }
        $dividends = $reads['dividends'] ?? [];
        if ($this->usable($dividends, 'dividends', $symbol, $cutoff, $maxAge)) {
            $status = $dividends['fields']['dividend_schedule_status'] ?? null;
            $result['dividend_status'] = in_array($status, ['partial_or_unknown', 'observed_cycle_unverified', 'inconsistent_dates'], true) ? $status : 'unknown';
            $result['corporate_action_status'] = 'dividend_subset_only_no_split_merger_coverage';
        }

        return $result;
    }

    private function usable(array $entry, string $operation, mixed $symbol, CarbonImmutable $cutoff, int $maxAge): bool
    {
        $expected = config('robinhood_mcp.operations.'.$operation.'.fingerprint');
        if (! is_string($symbol) || ($entry['provider'] ?? null) !== TrustedHttp::RESOURCE || ($entry['complete'] ?? false) !== true ||
            ($entry['fields']['symbol'] ?? null) !== $symbol || ! is_string($expected) || ($entry['contract_fingerprint'] ?? null) !== $expected ||
            ! is_string($entry['retrieved_at'] ?? null) || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/D', $entry['retrieved_at'])) {
            return false;
        }
        try {
            $retrieved = CarbonImmutable::parse($entry['retrieved_at']);

            return $retrieved->lte($cutoff) && $retrieved->gte($cutoff->subSeconds($maxAge));
        } catch (\InvalidArgumentException) {
            return false;
        }
    }
}
