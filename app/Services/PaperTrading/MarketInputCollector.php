<?php

namespace App\Services\PaperTrading;

use App\Jobs\CollectPaperMarketInput;
use App\Models\McpConnection;
use App\Models\PaperTrading\MarketInput;
use App\Models\PaperTrading\Portfolio;
use App\Models\User;
use App\Services\Monitoring\AccountScope;
use App\Services\Monitoring\MarketCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Ramsey\Uuid\Uuid;

final class MarketInputCollector
{
    public function tick(bool $foreground = false, ?int $account = null, int $limit = 5, bool $scheduled = false): int
    {
        if (! config('paper.market_collection_enabled') || ! config('paper.market_inputs_enabled')) {
            return 0;
        }
        $now = CarbonImmutable::now();
        $regular = app(MarketCalendar::class)->at($now)['status'] === 'regular';
        if ($scheduled && ! $regular && $now->minute !== 0) {
            return 0;
        }
        $lock = Cache::lock('paper-market-collector', 600);
        if (! $lock->get()) {
            return 0;
        }
        try {
            $operators = User::whereIn('email', config('mcp.operator_emails'))->pluck('id');
            $count = 0;
            foreach (McpConnection::whereIn('user_id', $operators)->where('status', 'connected')->whereNotNull('bound_account_id')
                ->when($account !== null, fn ($q) => $q->whereKey($account))->get() as $connection) {
                $scope = AccountScope::key($connection);
                $portfolio = Portfolio::where('mcp_connection_id', $connection->id)->where('scope', $scope)->first();
                $execution = $portfolio ? ExecutionPolicies::current($portfolio) : null;
                if (! $execution) {
                    continue;
                }
                $latest = MarketInput::where('mcp_connection_id', $connection->id)->where('scope', $scope)->where('origin', 'broker_read')
                    ->selectRaw('symbol, MAX(recorded_at) AS last_recorded')->groupBy('symbol')->pluck('last_recorded', 'symbol')->all();
                $symbols = $execution->payload['allowed_symbols'];
                usort($symbols, fn ($a, $b) => strcmp($latest[$a] ?? '', $latest[$b] ?? '') ?: strcmp($a, $b));
                foreach ($symbols as $symbol) {
                    $age = $scheduled && ! $regular ? 60 : 5;
                    if (isset($latest[$symbol]) && CarbonImmutable::parse($latest[$symbol])->gte($now->subMinutes($age))) {
                        continue;
                    }
                    if ($count >= max(1, min(5, $limit))) {
                        break 2;
                    }
                    $slot = intdiv($now->getTimestamp(), 300);
                    $request = Uuid::uuid5(Uuid::NAMESPACE_URL, 'paper-market:'.$connection->id.':'.$scope.':'.$execution->id.':'.$symbol.':'.$slot)->toString();
                    $job = new CollectPaperMarketInput($connection->id, $scope, $connection->auth_generation, $execution->id, $symbol, $request, $now->toISOString());
                    if ($foreground) {
                        $job->handle(app(PaperMarketInputs::class));
                    } else {
                        dispatch($job);
                    }
                    $count++;
                }
            }

            return $count;
        } finally {
            $lock->release();
        }
    }
}
