<?php

declare(strict_types=1);

namespace App\Services\PaperTrading;

use App\Support\Mcp\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;

/** Explicit synthetic input, never a broker read or proof of real market liquidity. */
final readonly class SimulationQuote
{
    public array $data;

    public function __construct(array $data)
    {
        $rules = ['key' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9:._-]+$/D'],
            'symbol' => ['required', 'string', 'regex:/^[A-Z][A-Z0-9.-]{0,15}$/D'],
            'provenance' => ['required', 'in:synthetic'], 'source_at' => ['nullable', 'date_format:Y-m-d\TH:i:sP'],
            'retrieved_at' => ['required', 'date_format:Y-m-d\TH:i:sP'],
            'asset_type' => ['nullable', 'in:stock,etf'], 'eligible' => ['present'], 'fractional' => ['present'],
            'leveraged' => ['present'], 'inverse' => ['present'],
            'corporate_actions_clear' => ['present'], 'bid' => ['nullable'], 'ask' => ['nullable'], 'bid_size' => ['nullable'], 'ask_size' => ['nullable']];
        abort_if(array_diff(array_keys($data), array_keys($rules)), 422, 'Unsupported synthetic quote field.');
        $normalized = array_replace(array_fill_keys(array_keys($rules), null), Validator::make($data, $rules)->validate());
        foreach (['source_at', 'retrieved_at'] as $field) {
            if ($normalized[$field] !== null) {
                $normalized[$field] = CarbonImmutable::parse($normalized[$field])->utc()->toIso8601String();
            }
        }
        $this->data = $normalized;
        foreach (['eligible', 'fractional', 'corporate_actions_clear', 'leveraged', 'inverse'] as $field) {
            abort_unless(is_bool($this->data[$field]) || $this->data[$field] === null, 422, 'Quote classification must be boolean or unknown.');
        }
        foreach (['bid', 'ask', 'bid_size', 'ask_size'] as $field) {
            if ($this->data[$field] !== null) {
                abort_unless(is_string($this->data[$field]) && preg_match('/^\d{1,12}(?:\.\d{1,8})?$/D', $this->data[$field]), 422, 'Quote numbers must be bounded decimal strings.');
            }
        }
    }

    public function reason(CarbonImmutable $now, int $maxAge): ?string
    {
        $d = $this->data;
        if ($d['source_at'] === null || $d['bid'] === null || $d['ask'] === null) {
            return 'quote_unknown';
        }
        $source = CarbonImmutable::parse($d['source_at']);
        $retrieved = CarbonImmutable::parse($d['retrieved_at']);
        if ($source->gt($retrieved) || $retrieved->gt($now) || $source->lt($now->subSeconds($maxAge))) {
            return 'quote_stale_or_future';
        }
        if ($d['eligible'] !== true || ! in_array($d['asset_type'], ['stock', 'etf'], true) || $d['leveraged'] !== false || $d['inverse'] !== false) {
            return 'instrument_ineligible_or_unknown';
        }
        if ($d['corporate_actions_clear'] !== true) {
            return 'corporate_action_unresolved';
        }
        if (Decimal::from($d['bid'])->compareTo(Decimal::from('0')) <= 0 || Decimal::from($d['ask'])->compareTo(Decimal::from($d['bid'])) < 0) {
            return 'invalid_spread';
        }

        return null;
    }
}
