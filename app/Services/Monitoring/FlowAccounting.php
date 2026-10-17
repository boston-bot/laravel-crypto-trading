<?php

namespace App\Services\Monitoring;

use App\Support\Mcp\Decimal;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/** Deterministic unitization contract. Runtime remains unavailable without verified flow coverage. */
final class FlowAccounting
{
    public function calculate(?array $baseline, array $events, string $endingEquity, bool $completeFlowCoverage): array
    {
        if (! $baseline || ! $completeFlowCoverage) {
            return ['available' => false, 'reason' => 'baseline_or_flow_coverage_unknown', 'return' => null, 'nav' => null];
        }
        $equity = Decimal::from($baseline['equity']);
        $units = Decimal::from($baseline['units']);
        $zero = Decimal::from('0');
        if ($equity->compareTo($zero) <= 0 || $units->compareTo($zero) <= 0 || empty($baseline['as_of']) || empty($baseline['source_ref'])) {
            throw new InvalidArgumentException('Verified positive baseline required.');
        }
        $startNav = $equity->dividedBy($units, 18);
        $lastTime = CarbonImmutable::parse($baseline['as_of']);
        $seen = [];
        foreach ($events as $event) {
            if (empty($event['source_ref']) || isset($seen[$event['source_ref']]) || empty($event['occurred_at']) || empty($event['valuation_as_of'])) {
                throw new InvalidArgumentException('Unique, timed broker evidence required.');
            }
            $seen[$event['source_ref']] = true;
            $time = CarbonImmutable::parse($event['occurred_at']);
            if ($time->lt($lastTime) || $time->ne(CarbonImmutable::parse($event['valuation_as_of']))) {
                return ['available' => false, 'reason' => 'flow_timing_or_valuation_unknown', 'return' => null, 'nav' => null];
            }
            $lastTime = $time;
            $before = Decimal::from($event['equity_before']);
            if ($before->compareTo($zero) <= 0) {
                throw new InvalidArgumentException('A positive contemporaneous pre-flow valuation is required.');
            }
            $amount = Decimal::from($event['amount']);
            if (in_array($event['classification'], ['deposit', 'withdrawal'], true)) {
                if (($event['classification'] === 'deposit' && $amount->compareTo($zero) <= 0) || ($event['classification'] === 'withdrawal' && $amount->compareTo($zero) >= 0)) {
                    throw new InvalidArgumentException('Flow sign does not match classification.');
                }
                $nav = $before->dividedBy($units, 18);
                $units = $units->plus($amount->dividedBy($nav, 18));
                if ($units->compareTo($zero) <= 0) {
                    throw new InvalidArgumentException('Units must remain positive.');
                }
            } elseif (! in_array($event['classification'], ['fee', 'distribution', 'internal_transfer'], true)) {
                return ['available' => false, 'reason' => 'unclassified_flow', 'return' => null, 'nav' => null];
            }
            // Fees/distributions remain part of investment performance, not new investor units.
        }
        $ending = Decimal::from($endingEquity);
        if ($ending->compareTo($zero) < 0) {
            throw new InvalidArgumentException('Negative ending equity.');
        }
        $nav = $ending->dividedBy($units, 18);

        return ['available' => true, 'reason' => null, 'units' => (string) $units, 'nav' => (string) $nav,
            'return' => (string) $nav->dividedBy($startNav, 18)->plus(Decimal::from('-1'))];
    }
}
