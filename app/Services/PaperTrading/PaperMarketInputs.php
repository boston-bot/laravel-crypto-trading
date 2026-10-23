<?php

namespace App\Services\PaperTrading;

use App\Models\McpConnection;
use App\Models\PaperTrading\MarketInput;
use App\Models\PaperTrading\Portfolio;
use App\Models\User;
use App\Services\Broker\Agentic\BrokerGateway;
use App\Services\Broker\Agentic\Operation;
use App\Services\Broker\Agentic\QuoteBatch;
use App\Services\Broker\Agentic\ReadError;
use App\Services\Broker\Agentic\ReadFailure;
use App\Services\Broker\Agentic\ReadResult;
use App\Services\Broker\Agentic\TrustedHttp;
use App\Services\MarketData\Alpaca\CorporateActions;
use App\Services\Monitoring\AccountScope;
use App\Support\Mcp\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Ramsey\Uuid\Uuid;

/** Only reviewed adapter fields establish facts. Research text and model metadata cannot grant eligibility. */
final class PaperMarketInputs
{
    public function __construct(private BrokerGateway $broker) {}

    public function collect(McpConnection $connection, int $actor, string $symbol, string $requestId): MarketInput
    {
        Validator::make(['symbol' => $symbol, 'request_id' => $requestId], ['symbol' => ['required', 'regex:/^[A-Z][A-Z0-9.-]{0,15}$/D'], 'request_id' => ['required', 'uuid']])->validate();
        abort_unless(config('paper.market_inputs_enabled'), 409, 'Paper market collection is disabled.');
        $this->authorize($connection, $actor);
        if (RuleEligibilityPolicies::selected($connection)) {
            abort_unless(in_array($symbol, app(SymbolUniverse::class)->reads($connection, CarbonImmutable::now()), true), 422, 'Collect current watchlist symbols or holdings only.');
        }
        if ($repeat = $this->repeat($connection, $symbol, $requestId)) {
            return $repeat;
        }
        // Every broker read for this symbol shares one MCP session and catalog check.
        [$payload, $source, $retrieved, $reason] = $this->broker->within($connection, fn (): array => $this->gather($connection, $symbol));

        return $this->record($connection, $actor, $symbol, $requestId, 'broker_read', $payload, $source, $retrieved, $reason);
    }

    /** One shared MCP session; quotes batched, other contracts remain exact-symbol reads. */
    public function collectMany(McpConnection $connection, int $actor, array $symbols, string $requestId, callable $beforeRead, ?callable $afterBatch = null): array
    {
        Validator::make(['symbols' => $symbols, 'request_id' => $requestId], [
            'symbols' => ['required', 'array', 'min:1', 'max:250'], 'symbols.*' => ['required', 'string', 'distinct', 'regex:/^[A-Z][A-Z0-9.-]{0,15}$/D'],
            'request_id' => ['required', 'uuid'],
        ])->validate();
        abort_unless(config('paper.batch_collection_enabled') && config('paper.market_inputs_enabled'), 409, 'Batch collection is disabled.');
        $current = $this->authorize($connection, $actor);
        abort_unless($current->account_role === 'agentic_monitoring', 409, 'Select the agentic account.');
        $allowed = app(SymbolUniverse::class)->reads($connection, CarbonImmutable::now());
        abort_if(array_diff($symbols, $allowed), 422, 'Collect current watchlist symbols or holdings only.');
        $rows = [];
        $size = max(1, min(25, (int) config('paper.market_batch_symbols', 5)));
        $this->broker->within($connection, function () use ($connection, $actor, $symbols, $requestId, $beforeRead, $afterBatch, $size, &$rows) {
            foreach (array_chunk($symbols, $size) as $chunk) {
                $pending = [];
                $batchRows = [];
                $stopped = false;
                foreach ($chunk as $symbol) {
                    $key = Uuid::uuid5($requestId, $symbol)->toString();
                    if ($repeat = $this->repeat($connection, $symbol, $key)) {
                        $rows[$symbol] = $batchRows[$symbol] = $repeat;
                    } else {
                        $pending[$symbol] = $key;
                    }
                }
                if (! $pending) {
                    if ($afterBatch) {
                        $afterBatch($batchRows);
                    }

                    continue;
                }
                try {
                    $beforeRead();
                } catch (ReadFailure) {
                    break;
                }
                $batch = QuoteBatch::read($this->broker, $connection, array_keys($pending), $beforeRead);
                foreach ($pending as $symbol => $key) {
                    // On a deadline or rate limit, leave unattempted symbols unknown instead of flooding the provider.
                    try {
                        $beforeRead();
                    } catch (ReadFailure) {
                        $stopped = true;
                        break;
                    }
                    $quote = $batch['quotes'][$symbol] ?? new ReadResult(TrustedHttp::RESOURCE, CarbonImmutable::now(), [], false);
                    [$payload, $source, $retrieved, $reason] = $this->gather($connection, $symbol, $quote, $beforeRead);
                    $rows[$symbol] = $batchRows[$symbol] = $this->record($connection, $actor, $symbol, $key, 'broker_read', $payload, $source, $retrieved, $reason);
                }
                if ($afterBatch && $batchRows) {
                    $afterBatch($batchRows);
                }
                if ($stopped) {
                    break;
                }
            }
        });

        return $rows;
    }

    /** @return array{0: array, 1: ?CarbonImmutable, 2: CarbonImmutable, 3: string} */
    private function gather(McpConnection $connection, string $symbol, ?ReadResult $quote = null, ?callable $beforeRead = null): array
    {
        $payload = ['provider' => TrustedHttp::RESOURCE, 'complete' => false, 'instrument' => null,
            'quote' => ['symbol' => $symbol, 'bid' => null, 'ask' => null, 'bid_as_of' => null, 'ask_as_of' => null,
                'bid_size' => null, 'ask_size' => null, 'eligible' => null, 'asset_type' => null, 'fractional' => null,
                'leveraged' => null, 'inverse' => null, 'corporate_actions_clear' => null],
            'coverage' => 'exact_symbol_prices_and_identity_only'];
        $source = null;
        $retrieved = CarbonImmutable::now();
        $reason = 'execution_fields_unreviewed';
        try {
            foreach ([Operation::Quotes, Operation::Instruments] as $operation) {
                if ($beforeRead) {
                    $beforeRead();
                }
                $read = $operation === Operation::Quotes && $quote !== null ? $quote : $this->broker->read($connection, $operation, ['symbol' => $symbol]);
                if ($read->source !== TrustedHttp::RESOURCE || ! $read->complete || $read->continuation !== null || count($read->records) !== 1 ||
                    $read->records[0]->operation !== $operation || ($read->records[0]->fields['symbol'] ?? null) !== $symbol) {
                    throw new ReadFailure(ReadError::Incomplete);
                }
                $retrieved = CarbonImmutable::instance($read->retrievedAt);
                $fields = $read->records[0]->fields;
                if ($operation === Operation::Instruments) {
                    $payload['instrument'] = ['id' => $fields['id'] ?? null, 'symbol' => $symbol, 'name' => $fields['name'] ?? null,
                        'retrieved_at' => $retrieved->toIso8601String()];
                } else {
                    $payload['quote']['retrieved_at'] = $retrieved->toIso8601String();
                    foreach (['bid', 'ask'] as $field) {
                        $payload['quote'][$field] = isset($fields[$field]) ? (string) $fields[$field] : null;
                    }
                    foreach (['bid_as_of', 'ask_as_of'] as $field) {
                        $payload['quote'][$field] = isset($fields[$field]) ? CarbonImmutable::parse($fields[$field])->utc()->toIso8601String() : null;
                    }
                    $times = [$payload['quote']['bid_as_of'], $payload['quote']['ask_as_of']];
                    $source = in_array(null, $times, true) ? null : CarbonImmutable::parse(min($times));
                }
            }
            $payload['complete'] = true; // Complete reviewed reads, not complete execution coverage.
        } catch (ReadFailure $failure) {
            $reason = 'broker_'.$failure->reason->value;
        } catch (\InvalidArgumentException) {
            $reason = 'broker_timestamp_malformed';
        }

        if (config('robinhood_mcp.execution_inputs_enabled')) {
            $payload['execution_reads'] = $this->executionReads($connection, $symbol, [Operation::Tradability, Operation::PriceBook], $beforeRead);
            foreach ($payload['execution_reads'] as $evidence) {
                if (isset($evidence['retrieved_at'])) {
                    $retrieved = $retrieved->max(CarbonImmutable::parse($evidence['retrieved_at']));
                }
            }
            $payload['coverage'] = 'prices_identity_and_separate_execution_read_evidence';
            $portfolio = Portfolio::where('mcp_connection_id', $connection->id)->where('scope', AccountScope::key($connection))->first();
            $execution = $portfolio ? ExecutionPolicies::current($portfolio) : null;
            if ($execution) {
                $payload['book_quote_match'] = app(BookQuoteMatcher::class)->match($payload, CarbonImmutable::now(), $execution->payload['quote_max_age_seconds']);
                if ($payload['book_quote_match']['reason'] === 'book_prices_or_quantities_do_not_match_quote') {
                    // One bounded read in the caller's existing session. Never copy book prices into a quote,
                    // relax equality, or keep retrying a moving market. Preserve the original observation.
                    $payload['quote_refresh'] = ['initial_quote' => $payload['quote'], 'status' => 'unavailable', 'error' => null];
                    try {
                        if ($beforeRead) {
                            $beforeRead();
                        }
                        $fresh = $this->broker->read($connection, Operation::Quotes, ['symbol' => $symbol]);
                        if ($fresh->source !== TrustedHttp::RESOURCE || ! $fresh->complete || $fresh->continuation !== null || count($fresh->records) !== 1 ||
                            $fresh->records[0]->operation !== Operation::Quotes || ($fresh->records[0]->fields['symbol'] ?? null) !== $symbol) {
                            throw new ReadFailure(ReadError::Incomplete);
                        }
                        $fields = $fresh->records[0]->fields;
                        $replacement = $payload['quote'];
                        foreach (['bid', 'ask'] as $field) {
                            $replacement[$field] = isset($fields[$field]) ? (string) $fields[$field] : null;
                        }
                        foreach (['bid_as_of', 'ask_as_of'] as $field) {
                            $replacement[$field] = isset($fields[$field]) ? CarbonImmutable::parse($fields[$field])->utc()->toIso8601String() : null;
                        }
                        $replacement['retrieved_at'] = CarbonImmutable::instance($fresh->retrievedAt)->toIso8601String();
                        $payload['quote'] = $replacement;
                        $times = [$replacement['bid_as_of'], $replacement['ask_as_of']];
                        $source = in_array(null, $times, true) ? null : CarbonImmutable::parse(min($times));
                        $retrieved = $retrieved->max(CarbonImmutable::instance($fresh->retrievedAt));
                        $payload['quote_refresh']['status'] = 'read_complete';
                    } catch (ReadFailure $failure) {
                        $payload['quote_refresh']['error'] = $failure->reason->value;
                    } catch (\InvalidArgumentException) {
                        $payload['quote_refresh']['error'] = 'broker_timestamp_malformed';
                    }
                    $payload['book_quote_match'] = app(BookQuoteMatcher::class)->match($payload, CarbonImmutable::now(), $execution->payload['quote_max_age_seconds']);
                }
                foreach (['bid_size', 'ask_size'] as $field) {
                    $payload['quote'][$field] = $payload['book_quote_match'][$field];
                }
            }
        }

        if (config('robinhood_mcp.reference_data_enabled')) {
            $payload['reference_reads'] = $this->executionReads($connection, $symbol, [Operation::Classification, Operation::Dividends], $beforeRead);
            foreach ($payload['reference_reads'] as $entry) {
                if (isset($entry['retrieved_at'])) {
                    $retrieved = $retrieved->max(CarbonImmutable::parse($entry['retrieved_at']));
                }
            }
            $payload['reference_coverage'] = app(ReferenceDataCoverage::class)->inspect($payload, CarbonImmutable::now(), config('robinhood_mcp.max_age_seconds'));
            $payload['quote']['asset_type'] = $payload['reference_coverage']['asset_type'];
            // This subset cannot establish leverage/inverse exclusion, complete actions or execution permission.
        }

        $withinBudget = true;
        try {
            if ($beforeRead) {
                $beforeRead();
            }
        } catch (ReadFailure) {
            $withinBudget = false;
        }
        if (config('alpaca_data.enabled') && $withinBudget) {
            $day = CarbonImmutable::now('America/New_York')->startOfDay();
            $portfolio = Portfolio::where('mcp_connection_id', $connection->id)->where('scope', AccountScope::key($connection))->first();
            $start = $day->subDays(min(180, max(0, (int) config('alpaca_data.lookback_days'))));
            if ($portfolio) {
                $start = $start->min($portfolio->created_at->setTimezone('America/New_York')->startOfDay());
            }
            $payload['corporate_action_reads']['alpaca'] = app(CorporateActions::class)->read($symbol, $start->toDateString(),
                $day->addDays(min(180, max(0, (int) config('alpaca_data.lookahead_days'))))->toDateString());
            $entry = $payload['corporate_action_reads']['alpaca'];
            if ($entry['retrieved_at'] !== null) {
                $retrieved = $retrieved->max(CarbonImmutable::parse($entry['retrieved_at']));
            }
            // A complete process-date query is never an effective-date absence guarantee.
        }

        return [$payload, $source, $retrieved, $reason];
    }

    /** Optional, separately gated evidence. Never promote broker tradability to strategy eligibility. */
    private function executionReads(McpConnection $connection, string $symbol, array $operations = [Operation::Tradability, Operation::PriceBook], ?callable $beforeRead = null): array
    {
        $evidence = [];
        foreach ($operations as $operation) {
            $entry = ['provider' => TrustedHttp::RESOURCE, 'complete' => false, 'source_as_of' => null,
                'retrieved_at' => null, 'fields' => null, 'error' => null];
            try {
                if ($beforeRead) {
                    $beforeRead();
                }
                $read = $this->broker->read($connection, $operation, ['symbol' => $symbol]);
                $entry['retrieved_at'] = CarbonImmutable::instance($read->retrievedAt)->toIso8601String();
                if ($read->source !== TrustedHttp::RESOURCE || ! $read->complete || $read->continuation !== null || count($read->records) !== 1 ||
                    $read->records[0]->operation !== $operation || ($read->records[0]->fields['symbol'] ?? null) !== $symbol) {
                    throw new ReadFailure(ReadError::Incomplete);
                }
                $record = $read->records[0];
                if ($operation === Operation::Tradability && ($record->fields['account_id'] ?? null) !== $connection->bound_account_id) {
                    throw new ReadFailure(ReadError::AccountMismatch);
                }
                $entry['source_as_of'] = $record->sourceAsOf?->format(DATE_ATOM);
                if ($operation === Operation::PriceBook) {
                    $profile = config('robinhood_mcp.operations.price_book');
                    $entry['semantic_fingerprint'] = $connection->fresh()->capability_manifest[$profile['tool']]['semantic_fingerprint'] ?? null;
                }
                $entry['contract_fingerprint'] = config('robinhood_mcp.operations.'.$operation->value.'.fingerprint');
                $fields = match ($operation) {
                    Operation::Tradability => ['symbol', 'account_scope', 'broker_tradeable', 'fractional_tradability', 'extended_hours_fractional_tradability', 'internal_halt_reason'],
                    Operation::PriceBook => ['symbol', 'book_bid', 'book_ask', 'book_bid_quantity', 'book_ask_quantity', 'book_quantity_unit'],
                    Operation::Classification => ['id', 'symbol', 'asset_type'],
                    Operation::Dividends => ['symbol', 'market_date', 'dividend_per_share', 'ex_dividend_date', 'record_date', 'payable_date', 'dividend_schedule_status'],
                };
                // Decimal implements JSON serialization as a quoted string; no floats or account IDs escape.
                $entry['fields'] = json_decode(json_encode(array_intersect_key($record->fields, array_flip($fields)), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
                $entry['complete'] = true;
            } catch (ReadFailure $failure) {
                $entry['error'] = $failure->reason->value;
            }
            $evidence[$operation->value] = $entry;
        }

        return $evidence;
    }

    /** Test-only normalized provider stand-in. Never exposed as an operator evidence import. */
    public function fixture(McpConnection $connection, int $actor, SimulationQuote $quote, string $requestId): MarketInput
    {
        abort_unless(app()->environment('testing'), 403, 'Synthetic market fixtures are test-only.');
        $d = $quote->data;

        return $this->record($connection, $actor, $d['symbol'], $requestId, 'synthetic', ['complete' => true, 'provider' => 'synthetic_fixture',
            'quote' => $d + ['bid_as_of' => $d['source_at'], 'ask_as_of' => $d['source_at']], 'coverage' => 'synthetic_fixture'],
            $d['source_at'] ? CarbonImmutable::parse($d['source_at']) : null, CarbonImmutable::parse($d['retrieved_at']), null);
    }

    public function latest(McpConnection $connection, string $symbol, CarbonImmutable $cutoff): ?MarketInput
    {
        return MarketInput::where('mcp_connection_id', $connection->id)->where('scope', AccountScope::key($connection))->where('symbol', $symbol)
            ->where('retrieved_at', '<=', $cutoff->utc()->format('Y-m-d H:i:s.u'))->where('recorded_at', '<=', $cutoff->utc()->format('Y-m-d H:i:s.u'))->latest('recorded_at')->latest('id')->first();
    }

    public function executionReason(?MarketInput $input, CarbonImmutable $cutoff, int $maxAge): ?string
    {
        if (! $input || ! $input->payload['complete']) {
            return 'market_input_incomplete';
        }
        $q = $input->payload['quote'];
        if (! is_string($q['retrieved_at'] ?? null)) {
            return 'quote_retrieval_time_unknown';
        }
        $quoteRetrieved = CarbonImmutable::parse($q['retrieved_at']);
        foreach (['bid_as_of', 'ask_as_of'] as $field) {
            if (! is_string($q[$field] ?? null) || CarbonImmutable::parse($q[$field])->gt($quoteRetrieved) ||
                CarbonImmutable::parse($q[$field])->lt($cutoff->subSeconds($maxAge))) {
                return 'quote_component_time_unknown_or_stale';
            }
        }
        if ($quoteRetrieved->gt($input->retrieved_at) || $quoteRetrieved->gt($cutoff) || $input->retrieved_at->gt($cutoff) || $input->recorded_at->gt($cutoff)) {
            return 'market_input_after_cutoff';
        }
        try {
            $bid = Decimal::from($q['bid']);
            $ask = Decimal::from($q['ask']);
        } catch (\InvalidArgumentException) {
            return 'quote_prices_unknown_or_invalid';
        }
        if ($bid->compareTo(Decimal::from('0')) <= 0 || $ask->compareTo($bid) < 0) {
            return 'quote_prices_unknown_or_invalid';
        }
        $class = $input->origin === 'broker_read' && config('paper.instrument_classification_enabled')
            ? app(InstrumentEligibility::class)->inspect($input, $cutoff, $maxAge) : $q;
        if ($class['eligible'] === false) {
            return 'instrument_ineligible';
        }
        if ($class['eligible'] !== true || ! in_array($class['asset_type'], ['stock', 'etf'], true) || $class['leveraged'] !== false || $class['inverse'] !== false) {
            return 'instrument_eligibility_unverified';
        }
        $actionClear = $input->origin === 'broker_read' && config('paper.corporate_actions_enabled')
            ? app(CorporateActionClearance::class)->inspect($input, $cutoff, $maxAge)['clear'] : $q['corporate_actions_clear'];
        if ($actionClear !== true) {
            return 'corporate_action_coverage_unknown';
        }
        if ($q['bid_size'] === null || $q['ask_size'] === null) {
            return 'liquidity_coverage_unknown';
        }

        return null;
    }

    private function authorize(McpConnection $connection, int $actor): McpConnection
    {
        $current = McpConnection::findOrFail($connection->id);
        abort_unless($current->user_id === $actor && User::whereKey($actor)->whereIn('email', config('mcp.operator_emails'))->exists(), 403);
        abort_unless($current->status === 'connected' && $current->bound_account_id && AccountScope::key($current) === AccountScope::key($connection) &&
            $current->auth_generation === $connection->auth_generation, 409, 'Account authorization or binding changed.');

        return $current;
    }

    private function repeat(McpConnection $connection, string $symbol, string $requestId): ?MarketInput
    {
        $row = MarketInput::where('mcp_connection_id', $connection->id)->where('scope', AccountScope::key($connection))->where('request_id', $requestId)->first();
        abort_if($row && $row->symbol !== $symbol, 409, 'Collection request already used for another symbol.');

        return $row;
    }

    private function record(McpConnection $connection, int $actor, string $symbol, string $requestId, string $origin, array $payload, ?CarbonImmutable $source, CarbonImmutable $retrieved, ?string $reason): MarketInput
    {
        return DB::transaction(function () use ($connection, $actor, $symbol, $requestId, $origin, $payload, $source, $retrieved, $reason) {
            McpConnection::whereKey($connection->id)->lockForUpdate()->firstOrFail();
            $this->authorize($connection, $actor);
            if ($origin === 'broker_read' && RuleEligibilityPolicies::selected($connection)) {
                abort_unless(in_array($symbol, app(SymbolUniverse::class)->reads($connection, CarbonImmutable::now()), true), 409, 'Symbol membership changed during collection.');
            }
            if ($repeat = $this->repeat($connection, $symbol, $requestId)) {
                abort_unless($repeat->origin === $origin, 409, 'Collection request already used for another origin.');

                return $repeat;
            }

            if ($origin === 'broker_read') {
                $payload['auth_generation'] = $connection->auth_generation;
            }

            return MarketInput::create(['mcp_connection_id' => $connection->id, 'scope' => AccountScope::key($connection), 'request_id' => $requestId,
                'symbol' => $symbol, 'origin' => $origin, 'status' => $reason ? 'incomplete_coverage' : 'fixture_only', 'reason' => $reason,
                'payload' => $payload, 'source_at' => $source, 'retrieved_at' => $retrieved, 'recorded_at' => now()]);
        });
    }
}
