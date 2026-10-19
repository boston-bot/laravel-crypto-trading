<?php

namespace App\Services\PaperTrading;

use App\Models\McpConnection;
use App\Models\PaperTrading\Portfolio;
use App\Models\PaperTrading\SimulationEvent;
use App\Models\PaperTrading\SimulationOrder;
use App\Models\PaperTrading\SimulationPosition;
use App\Models\User;
use App\Services\Monitoring\AccountScope;
use App\Support\Mcp\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Explicit local paper processing only. Does not collect data, call AI, schedule work or submit broker orders. */
final class RealDataPaperFills
{
    public function process(McpConnection $connection, int $actor, int $orderId): SimulationOrder
    {
        abort_unless(config('paper.real_data_fills_enabled'), 409, 'Real-data paper fills are disabled.');

        return DB::transaction(function () use ($connection, $actor, $orderId) {
            $current = McpConnection::whereKey($connection->id)->lockForUpdate()->firstOrFail();
            abort_unless($current->user_id === $actor && User::whereKey($actor)->whereIn('email', config('mcp.operator_emails'))->exists(), 403);
            abort_unless($current->bound_account_id && AccountScope::key($current) === AccountScope::key($connection), 409, 'Account binding changed.');
            $portfolio = Portfolio::where('mcp_connection_id', $current->id)->where('scope', AccountScope::key($current))->lockForUpdate()->firstOrFail();
            $order = SimulationOrder::whereKey($orderId)->where('paper_portfolio_id', $portfolio->id)->lockForUpdate()->firstOrFail();
            abort_unless(($order->intent['origin'] ?? null) === 'real_data_paper_simulation', 409, 'Select a real-data paper order.');
            if (! in_array($order->status, ['open', 'partial'], true)) {
                return $order;
            }
            $simulator = app(OfflineSimulator::class);
            // Release invalidated reservations even when reference/quote data is unavailable.
            $order = $simulator->revalidate($current, $actor, $order->id);
            if (! in_array($order->status, ['open', 'partial'], true)) {
                return $order;
            }
            if (config('paper.corporate_actions_enabled')) {
                $input = app(PaperMarketInputs::class)->latest($current, $order->symbol, CarbonImmutable::now());
                if ($input && isset($input->payload['corporate_action_reads']['alpaca'])) {
                    app(PaperCorporateActions::class)->process($current, $actor, $input->id);
                    $order->refresh();
                    if (! in_array($order->status, ['open', 'partial'], true)) {
                        return $order;
                    }
                }
            }
            $ids = [];
            try {
                $symbols = [$order->symbol, ...SimulationPosition::where('paper_portfolio_id', $portfolio->id)->get()
                    ->filter(fn ($p) => Decimal::from($p->quantity)->compareTo(Decimal::from('0')) > 0)->pluck('symbol')->all()];
                $marks = [];
                $cutoff = CarbonImmutable::now();
                foreach (array_unique($symbols) as $symbol) {
                    $input = app(PaperMarketInputs::class)->latest($current, $symbol, $cutoff);
                    $ids[$symbol] = $input?->id;
                    if (! $input || $input->origin !== 'broker_read') {
                        throw new DecisionFailure('broker_observation_missing', 'unavailable');
                    }
                    if (($input->payload['quote']['corporate_actions_clear'] ?? null) === false) {
                        // A detected action stays blocked until a separate reviewed adjustment, even if a later read says clear.
                        SimulationPosition::where('paper_portfolio_id', $portfolio->id)->where('symbol', $symbol)
                            ->update(['blocked_reason' => 'corporate_action_adjustment_required']);
                    }
                    $marks[$symbol] = BrokerSimulationQuote::fromStored($current, $input->id, $cutoff, $order->assumptions['quote_max_age_seconds']);
                }

                return $simulator->observe($current, $actor, $order->id, $marks[$order->symbol], $marks);
            } catch (DecisionFailure $failure) {
                $key = 'real-inputs:'.hash('sha256', json_encode([$ids, $failure->reason], JSON_THROW_ON_ERROR));
                SimulationEvent::firstOrCreate(['sim_order_id' => $order->id, 'event_key' => $key],
                    ['kind' => 'unfilled', 'reason' => $failure->reason, 'quantity' => '0', 'price' => null, 'fee' => '0', 'cash_delta' => '0',
                        'ledger_version' => $portfolio->ledger_version, 'evidence' => ['mode' => 'real_data_paper_simulation', 'market_input_ids' => $ids,
                            'order_status' => $order->status, 'broker_executable' => false], 'recorded_at' => now()]);

                return $order;
            }
        });
    }
}
