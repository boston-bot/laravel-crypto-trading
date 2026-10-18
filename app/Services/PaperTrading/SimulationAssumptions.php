<?php

declare(strict_types=1);

namespace App\Services\PaperTrading;

use App\Support\Mcp\Decimal;
use Illuminate\Support\Facades\Validator;

/** Explicit paper fill assumptions. Proposal-linked orders must match the applied execution version. */
final readonly class SimulationAssumptions
{
    public array $data;

    public function __construct(array $data)
    {
        $decimal = ['required', 'string', 'regex:/^\d{1,12}(?:\.\d{1,8})?$/D'];
        $rules = ['fee_per_share' => $decimal, 'slippage_bps' => $decimal,
            'quote_max_age_seconds' => ['required', 'integer', 'min:1', 'max:300'],
            'allow_fractional' => ['required'], 'allow_fractional_limit' => ['required'],
            'session_open_equity' => ['required', 'string', 'regex:/^\d{1,20}(?:\.\d{1,18})?$/D'], 'session_date' => ['required', 'date_format:Y-m-d']];
        abort_if(array_diff(array_keys($data), array_keys($rules)), 422, 'Unsupported simulation assumption.');
        $normalized = Validator::make($data, $rules)->validate();
        $normalized['quote_max_age_seconds'] = (int) $normalized['quote_max_age_seconds'];
        $this->data = array_replace(array_fill_keys(array_keys($rules), null), $normalized);
        foreach (['allow_fractional', 'allow_fractional_limit'] as $field) {
            abort_unless(is_bool($this->data[$field]), 422, 'Simulation switches must be booleans.');
        }
        abort_unless(Decimal::from($data['session_open_equity'])->compareTo(Decimal::from('0')) > 0, 422, 'A positive synthetic session-open baseline is required.');
        abort_if(Decimal::from($data['slippage_bps'])->compareTo(Decimal::from('9999')) > 0, 422, 'Unsupported slippage assumption.');
    }
}
