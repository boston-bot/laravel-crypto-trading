<?php

declare(strict_types=1);

namespace App\Services\Crypto;

use App\Models\PaperTrading\CashEntry;
use App\Models\PaperTrading\InstrumentPolicy;
use App\Models\PaperTrading\Portfolio;
use App\Models\User;
use App\Support\Mcp\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/** Owner-applied, standalone virtual sleeve. No brokerage binding, permission change, scheduler or submission. */
final class CryptoPolicies
{
    public const VERSION = 'crypto_paper_policy_v2';

    public const OWNER = ['initial_cash' => '400', 'risk_per_trade_pct' => '5', 'max_position_pct' => '25',
        'max_open_positions' => 3, 'daily_loss_pause_pct' => '5', 'universe' => 'any_eligible_spot_usd'];

    public function apply(int $actor, string $request, int $base = 0, array $costs = []): InstrumentPolicy
    {
        Validator::make(['request' => $request, 'base' => $base], ['request' => 'required|uuid', 'base' => 'integer|min:0'])->validate();
        abort_if(count($costs) > 25, 422, 'At most 25 product fee schedules per policy.');
        $schedules = [];
        foreach ($costs as $cost) {
            $schedule = $this->cost($cost);
            abort_if(isset($schedules[$schedule['product_id']]), 422, 'Duplicate product fee schedule.');
            $schedules[$schedule['product_id']] = $schedule;
        }
        ksort($schedules);
        $payload = ['version' => self::VERSION, 'asset_class' => 'crypto', 'venue' => 'coinbase', 'mode' => 'paper',
            'owner' => self::OWNER, 'parameters' => CryptoParameters::current(), 'costs' => $schedules,
            'broker_executable' => false, 'purpose' => 'unvalidated_research'];
        $fingerprint = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($actor, $request, $base, $payload, $fingerprint) {
            $user = User::whereKey($actor)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($user->email, config('mcp.operator_emails'), true), 403);
            $portfolio = Portfolio::where(['actor_id' => $actor, 'asset_class' => 'crypto', 'venue' => 'coinbase'])->lockForUpdate()->first();
            if ($portfolio) {
                $repeat = InstrumentPolicy::where('paper_portfolio_id', $portfolio->id)->where('request_id', $request)->first();
                if ($repeat) {
                    abort_unless($repeat->fingerprint === $fingerprint, 409, 'Request used for different settings.');

                    return $repeat;
                }
            }
            $latest = $portfolio ? InstrumentPolicy::where('paper_portfolio_id', $portfolio->id)->max('version') : 0;
            abort_unless((int) $latest === $base, 409, 'Policy changed; reload the current version.');
            if (! $portfolio) {
                $portfolio = Portfolio::create(['mcp_connection_id' => null, 'actor_id' => $actor, 'asset_class' => 'crypto', 'venue' => 'coinbase',
                    'scope' => 'crypto:coinbase:'.$actor, 'starting_cash' => self::OWNER['initial_cash'], 'cash' => self::OWNER['initial_cash'],
                    'reserved_cash' => '0', 'ledger_version' => 1, 'policy_revision' => 1, 'created_at' => now()]);
                CashEntry::create(['paper_portfolio_id' => $portfolio->id, 'actor_id' => $actor, 'reference' => 'opening:'.$portfolio->id,
                    'kind' => 'opening_balance', 'amount' => self::OWNER['initial_cash'], 'balance_after' => self::OWNER['initial_cash'],
                    'ledger_version' => 1, 'recorded_at' => now()]);
            }

            return InstrumentPolicy::create(['paper_portfolio_id' => $portfolio->id, 'actor_id' => $actor, 'version' => $base + 1,
                'request_id' => $request, 'fingerprint' => $fingerprint, 'payload' => $payload, 'applied_at' => now()]);
        });
    }

    /** A rate is an explicit product-specific observation, never an assumed default fee tier. */
    public function cost(array $input): array
    {
        $rules = ['version' => 'required|string|max:48', 'product_id' => ['required', 'regex:'.CoinbasePublicMarketData::PRODUCT_PATTERN],
            'cost_scope' => 'required|in:product_all_in',
            'maker_rate' => ['required', 'string', 'regex:/^\d{1,2}(?:\.\d{1,18})?$/D'],
            'taker_rate' => ['required', 'string', 'regex:/^\d{1,2}(?:\.\d{1,18})?$/D'],
            'observed_at' => ['required', 'string', 'date', 'regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/D'],
            'valid_until' => ['required', 'string', 'date', 'regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/D'],
            'source' => 'required|string|max:256'];
        abort_if(array_diff(array_keys($input), array_keys($rules)), 422, 'Unsupported cost field.');
        $data = Validator::make($input, $rules)->validate();
        foreach (['maker_rate', 'taker_rate'] as $field) {
            abort_if(Decimal::from($data[$field])->compareTo(Decimal::from('1')) > 0, 422, 'Fee rate exceeds 100%.');
        }
        $observed = CarbonImmutable::parse($data['observed_at'])->utc();
        $until = CarbonImmutable::parse($data['valid_until'])->utc();
        abort_unless($observed->lte(CarbonImmutable::now()) && $until->gt($observed) && $until->lte($observed->addDay()), 422, 'Fee observation must have a validity window of at most one day.');

        return ['kind' => 'venue_notional_rate_v1', 'venue' => 'coinbase', 'version' => $data['version'], 'product_id' => $data['product_id'],
            'cost_scope' => $data['cost_scope'], 'modeled_fee_role' => 'maximum_maker_taker_v1',
            'maker_rate' => $data['maker_rate'], 'taker_rate' => $data['taker_rate'], 'observed_at' => $observed->toISOString(),
            'valid_until' => $until->toISOString(), 'source' => $data['source']];
    }
}
