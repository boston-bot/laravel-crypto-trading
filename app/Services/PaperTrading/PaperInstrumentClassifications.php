<?php

declare(strict_types=1);

namespace App\Services\PaperTrading;

use App\Models\McpConnection;
use App\Models\PaperTrading\InstrumentClassification;
use App\Models\PaperTrading\Portfolio;
use App\Models\User;
use App\Services\MarketData\Sec\EdgarClassifications;
use App\Services\Monitoring\AccountScope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Ramsey\Uuid\Uuid;

/** Immutable public-filing evidence for the applied symbol universe. No broker call, policy change or eligibility grant. */
final class PaperInstrumentClassifications
{
    public function __construct(private EdgarClassifications $edgar) {}

    public function collect(McpConnection $connection, int $actor, array $symbols, string $requestId): Collection
    {
        Validator::make(['symbols' => $symbols, 'request_id' => $requestId], ['symbols' => ['present', 'array', 'max:100'],
            'symbols.*' => ['string', 'regex:/^[A-Z][A-Z0-9.-]{0,15}$/D'], 'request_id' => ['required', 'uuid']])->validate();
        abort_unless(config('sec_edgar.enabled'), 409, 'SEC classification reads are disabled.');
        $this->authorize($connection, $actor);
        $scope = AccountScope::key($connection);
        $portfolio = Portfolio::where('mcp_connection_id', $connection->id)->where('scope', $scope)->first();
        $execution = $portfolio ? ExecutionPolicies::current($portfolio) : null;
        abort_unless($execution, 409, 'Applied execution settings are required.');
        $allowed = app(SymbolUniverse::class)->reads($connection, CarbonImmutable::now());
        $symbols = $symbols === [] ? $allowed : array_values(array_unique($symbols));
        abort_unless(array_diff($symbols, $allowed) === [], 422, 'Classify only symbols in the applied execution settings.');
        $requests = [];
        foreach ($symbols as $symbol) {
            $requests[$symbol] = Uuid::uuid5(Uuid::NAMESPACE_URL, 'paper-classification:'.$requestId.':'.$symbol)->toString();
        }
        $existing = InstrumentClassification::where('mcp_connection_id', $connection->id)->where('scope', $scope)->whereIn('request_id', $requests)->get()->keyBy('symbol');
        $missing = array_values(array_diff($symbols, $existing->keys()->all()));
        // Network reads happen outside the ledger transaction; each result is recorded under a fresh authorization check.
        $reads = $missing === [] ? [] : $this->edgar->readMany($missing, CarbonImmutable::now());
        $rows = collect();
        foreach ($symbols as $symbol) {
            $rows->push($existing[$symbol] ?? $this->record($connection, $actor, $symbol, $requests[$symbol], $reads[$symbol]));
        }

        return $rows;
    }

    /** Daily idempotent refresh for each bound operator connection with applied settings. */
    public function tick(): int
    {
        if (! config('sec_edgar.enabled')) {
            return 0;
        }
        $count = 0;
        $operators = User::whereIn('email', config('mcp.operator_emails'))->pluck('id');
        foreach (McpConnection::whereIn('user_id', $operators)->where('status', 'connected')->whereNotNull('bound_account_id')->get() as $connection) {
            $portfolio = Portfolio::where('mcp_connection_id', $connection->id)->where('scope', AccountScope::key($connection))->first();
            if (! $portfolio || ! ExecutionPolicies::current($portfolio)) {
                continue;
            }
            $day = CarbonImmutable::now('America/New_York')->toDateString();
            $request = Uuid::uuid5(Uuid::NAMESPACE_URL, 'paper-classification-daily:'.$connection->id.':'.AccountScope::key($connection).':'.$day)->toString();
            $count += $this->collect($connection, $connection->user_id, [], $request)->count();
        }

        return $count;
    }

    public function latestComplete(int $connectionId, string $scope, string $symbol, CarbonImmutable $cutoff): ?InstrumentClassification
    {
        $at = $cutoff->utc()->format('Y-m-d H:i:s.u');

        return InstrumentClassification::where('mcp_connection_id', $connectionId)->where('scope', $scope)->where('symbol', $symbol)
            ->where('status', 'complete')->where('retrieved_at', '<=', $at)->where('recorded_at', '<=', $at)->latest('recorded_at')->latest('id')->first();
    }

    private function authorize(McpConnection $connection, int $actor): McpConnection
    {
        $current = McpConnection::findOrFail($connection->id);
        abort_unless($current->user_id === $actor && User::whereKey($actor)->whereIn('email', config('mcp.operator_emails'))->exists(), 403);
        abort_unless($current->status === 'connected' && $current->bound_account_id && AccountScope::key($current) === AccountScope::key($connection) &&
            $current->auth_generation === $connection->auth_generation, 409, 'Account authorization or binding changed.');

        return $current;
    }

    private function record(McpConnection $connection, int $actor, string $symbol, string $requestId, array $read): InstrumentClassification
    {
        return DB::transaction(function () use ($connection, $actor, $symbol, $requestId, $read) {
            McpConnection::whereKey($connection->id)->lockForUpdate()->firstOrFail();
            $this->authorize($connection, $actor);
            $scope = AccountScope::key($connection);
            if ($repeat = InstrumentClassification::where('mcp_connection_id', $connection->id)->where('scope', $scope)->where('request_id', $requestId)->first()) {
                return $repeat;
            }
            $complete = $read['complete'] === true && is_string($read['basis']);

            return InstrumentClassification::create(['mcp_connection_id' => $connection->id, 'scope' => $scope, 'request_id' => $requestId,
                'actor_id' => $actor, 'symbol' => $symbol, 'provider' => $read['provider'], 'status' => $complete ? 'complete' : 'incomplete',
                'basis' => $complete ? $read['basis'] : null, 'reason' => $complete ? $read['reason'] : ($read['error'] ?? 'incomplete'),
                'payload' => $read + ['mode' => 'paper_reference_evidence', 'broker_executable' => false],
                'source_at' => $read['source_as_of'] ? CarbonImmutable::parse($read['source_as_of']) : null,
                'retrieved_at' => CarbonImmutable::parse($read['retrieved_at']), 'recorded_at' => now()]);
        });
    }
}
