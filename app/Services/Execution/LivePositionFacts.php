<?php

namespace App\Services\Execution;

use App\Models\Execution\ExecutionFill;
use App\Models\Execution\OrderIntent;
use App\Models\McpConnection;
use App\Models\Monitoring\Holding;
use App\Models\Monitoring\Run;
use App\Models\Monitoring\Snapshot;
use App\Models\PaperTrading\StrategySignal;
use App\Services\Monitoring\AccountScope;
use App\Services\PaperTrading\SignalRules;
use App\Support\Mcp\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Reconstruct live episodes solely from this account's immutable broker-confirmed executions. No paper ledger. */
final class LivePositionFacts
{
    public function symbols(McpConnection $connection, CarbonImmutable $cutoff): array
    {
        return $this->fills($connection, $cutoff)->get()->groupBy('symbol')->filter(function ($fills) {
            $net = Decimal::from('0');
            foreach ($fills as $fill) {
                $q = Decimal::from((string) $fill->quantity);
                $net = $fill->side === 'BUY' ? $net->plus($q) : $net->minus($q);
            }

            return $net->compareTo(Decimal::from('0')) !== 0;
        })->keys()->sort()->values()->all();
    }

    public function inspect(McpConnection $connection, string $symbol, CarbonImmutable $cutoff): array
    {
        $fills = $this->fills($connection, $cutoff)->where('symbol', $symbol)->orderBy('executed_at')->orderBy('id')->get();
        $net = Decimal::from('0');
        $buys = [];
        $entryAt = null;
        $lastExit = null;
        $invalid = false;
        foreach ($fills as $fill) {
            $q = Decimal::from((string) $fill->quantity);
            if ($fill->side === 'BUY') {
                if ($net->compareTo(Decimal::from('0')) === 0) {
                    $buys = [];
                    $entryAt = $fill->executed_at;
                }
                $buys[] = $fill;
                $net = $net->plus($q);
            } else {
                $net = $net->minus($q);
                if ($net->compareTo(Decimal::from('0')) < 0) {
                    $invalid = true;
                }
                if ($net->compareTo(Decimal::from('0')) === 0) {
                    $lastExit = $fill->executed_at->toISOString();
                    $buys = [];
                }
            }
        }
        $result = ['held' => $net->compareTo(Decimal::from('0')) !== 0, 'quantity' => (string) $net,
            'fill_ids' => $fills->pluck('id')->all(), 'last_exit_at' => $lastExit, 'context' => null, 'reason' => null];
        if (! $result['held']) {
            return $result;
        }
        $deny = fn ($reason) => array_replace($result, ['reason' => $reason]);
        if ($invalid || count(array_unique(array_map(fn ($f) => $f->intent_id, $buys))) !== 1) {
            return $deny('live_position_history_unverified');
        }
        $snapshot = Snapshot::where('mcp_connection_id', $connection->id)->where('scope', AccountScope::key($connection))->where('status', 'complete')
            ->whereIn('run_id', Run::where('mcp_connection_id', $connection->id)->where('auth_generation', $connection->auth_generation)->select('id'))
            ->where('published_at', '<=', $cutoff->format('Y-m-d H:i:s.u'))->where('retrieved_at', '<=', $cutoff->format('Y-m-d H:i:s.u'))->latest('publication_version')->first();
        if (! $snapshot || $snapshot->retrieved_at->lte($cutoff->subMinutes(5))) {
            return $deny('live_broker_positions_unverified');
        }
        $held = Decimal::from('0');
        foreach (Holding::where('snapshot_id', $snapshot->id)->where('symbol', $symbol)->get() as $holding) {
            $held = $held->plus(Decimal::from((string) $holding->quantity));
        }
        if ($net->compareTo($held) > 0) {
            return $deny('live_broker_position_disagrees');
        }
        $intent = OrderIntent::findOrFail($buys[0]->intent_id);
        $signal = StrategySignal::find($intent->signal_id);
        $atr = $signal?->payload['result']['facts']['entry_atr'] ?? null;
        $parameters = $signal?->payload['parameters']['values'] ?? null;
        if (! $signal || $signal->status !== 'signal' || $signal->side !== 'BUY' || $signal->symbol !== $symbol ||
            $intent->payload['symbol'] !== $symbol || $intent->payload['side'] !== 'BUY' ||
            $intent->payload['signal_input_fingerprint'] !== $signal->input_fingerprint || $signal->recorded_at->gt($intent->recorded_at) ||
            $signal->rule_version !== SignalRules::VERSION || ! is_string($atr) ||
            ! is_array($parameters) || ! isset($parameters['initial_stop_atr'], $parameters['trailing_stop_atr'], $parameters['time_exit_sessions'])) {
            return $deny('live_entry_provenance_unverified');
        }
        $quantity = $notional = Decimal::from('0');
        foreach ($buys as $fill) {
            $q = Decimal::from((string) $fill->quantity);
            $quantity = $quantity->plus($q);
            $notional = $notional->plus($q->multipliedBy(Decimal::from((string) $fill->price)));
        }
        $entry = $notional->dividedBy($quantity, 18);
        $stop = $entry->minus(Decimal::from($atr)->multipliedBy(Decimal::from($parameters['initial_stop_atr'])));
        if (Decimal::from($atr)->compareTo(Decimal::from('0')) <= 0 || $stop->compareTo(Decimal::from('0')) <= 0 || $stop->compareTo($entry) >= 0) {
            return $deny('live_position_stop_invalid');
        }
        $result['snapshot_id'] = $snapshot->id;
        $result['context'] = ['entry_order_id' => $intent->id, 'entry_signal_id' => $signal->id, 'entry_at' => $entryAt->toISOString(),
            'entry_price' => (string) $entry, 'entry_atr' => $atr, 'initial_stop' => (string) $stop,
            'parameters' => $parameters, 'parameters_version' => $signal->parameters_version];

        return $result;
    }

    private function fills(McpConnection $connection, CarbonImmutable $cutoff)
    {
        return ExecutionFill::whereIn('execution_account_id', DB::table('mcp_execution_accounts')->where('scope', AccountScope::key($connection))->select('id'))
            ->where('recorded_at', '<=', $cutoff->format('Y-m-d H:i:s.u'))->where('executed_at', '<=', $cutoff->format('Y-m-d H:i:s.u'));
    }
}
