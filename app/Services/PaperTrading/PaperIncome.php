<?php

namespace App\Services\PaperTrading;

use App\Models\PaperTrading\CashEntry;
use App\Models\PaperTrading\CorporateActionRecord;
use App\Models\PaperTrading\Portfolio;
use App\Support\Mcp\Decimal;

/** Gross paper distributions: receivables contribute equity, never spendable cash. */
final class PaperIncome
{
    public function receivable(Portfolio $portfolio): Decimal
    {
        $amount = Decimal::from('0');
        foreach (CorporateActionRecord::where('paper_portfolio_id', $portfolio->id)->where('phase', 'entitlement')->where('status', 'captured')->get() as $record) {
            if (! CorporateActionRecord::where('paper_portfolio_id', $portfolio->id)->where('provider_event_id', $record->provider_event_id)
                ->where('fingerprint', $record->fingerprint)->where('phase', 'application')->exists()) {
                $amount = $amount->plus(Decimal::from($record->payload['gross_amount']));
            }
        }

        return $amount;
    }

    public function received(Portfolio $portfolio): Decimal
    {
        $amount = Decimal::from('0');
        foreach (CashEntry::where('paper_portfolio_id', $portfolio->id)->where('kind', 'simulated_dividend')->cursor() as $entry) {
            $amount = $amount->plus(Decimal::from($entry->amount));
        }

        return $amount;
    }
}
