<?php

namespace App\Services\PaperTrading;

use App\Models\McpConnection;
use App\Models\PaperTrading\MarketInput;
use App\Models\User;
use App\Services\Broker\Agentic\BrokerGateway;
use App\Services\Broker\Agentic\Operation;
use App\Services\Broker\Agentic\ReadError;
use App\Services\Broker\Agentic\ReadFailure;
use App\Services\Broker\Agentic\TrustedHttp;
use App\Services\Monitoring\AccountScope;
use App\Support\Mcp\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/** Only reviewed adapter fields establish facts. Research text and model metadata cannot grant eligibility. */
final class PaperMarketInputs
{
    public function __construct(private BrokerGateway $broker) {}

    public function collect(McpConnection $connection, int $actor, string $symbol, string $requestId): MarketInput
    {
        Validator::make(['symbol' => $symbol, 'request_id' => $requestId], ['symbol' => ['required', 'regex:/^[A-Z][A-Z0-9.-]{0,15}$/D'], 'request_id' => ['required', 'uuid']])->validate();
        abort_unless(config('paper.market_inputs_enabled'), 409, 'Paper market collection is disabled.');
        $this->authorize($connection, $actor);
        if ($repeat = $this->repeat($connection, $symbol, $requestId)) {
            return $repeat;
        }
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
                $read = $this->broker->read($connection, $operation, ['symbol' => $symbol]);
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
            $payload['execution_reads'] = $this->executionReads($connection, $symbol);
            foreach ($payload['execution_reads'] as $evidence) {
                if (isset($evidence['retrieved_at'])) {
                    $retrieved = $retrieved->max(CarbonImmutable::parse($evidence['retrieved_at']));
                }
            }
            $payload['coverage'] = 'prices_identity_and_separate_execution_read_evidence';
        }

        return $this->record($connection, $actor, $symbol, $requestId, 'broker_read', $payload, $source, $retrieved, $reason);
    }

    /** Optional, separately gated evidence. Never promote broker tradability to strategy eligibility. */
    private function executionReads(McpConnection $connection, string $symbol): array
    {
        $evidence = [];
        foreach ([Operation::Tradability, Operation::PriceBook] as $operation) {
            $entry = ['provider' => TrustedHttp::RESOURCE, 'complete' => false, 'source_as_of' => null,
                'retrieved_at' => null, 'fields' => null, 'error' => null];
            try {
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
                $fields = $operation === Operation::Tradability
                    ? ['symbol', 'account_scope', 'broker_tradeable', 'fractional_tradability', 'extended_hours_fractional_tradability', 'internal_halt_reason']
                    : ['symbol', 'book_bid', 'book_ask', 'book_bid_quantity', 'book_ask_quantity', 'book_quantity_unit'];
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
        if ($q['eligible'] !== true || ! in_array($q['asset_type'], ['stock', 'etf'], true) || $q['leveraged'] !== false || $q['inverse'] !== false) {
            return 'instrument_eligibility_unverified';
        }
        if ($q['corporate_actions_clear'] !== true) {
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
            if ($repeat = $this->repeat($connection, $symbol, $requestId)) {
                abort_unless($repeat->origin === $origin, 409, 'Collection request already used for another origin.');

                return $repeat;
            }

            return MarketInput::create(['mcp_connection_id' => $connection->id, 'scope' => AccountScope::key($connection), 'request_id' => $requestId,
                'symbol' => $symbol, 'origin' => $origin, 'status' => $reason ? 'incomplete_coverage' : 'fixture_only', 'reason' => $reason,
                'payload' => $payload, 'source_at' => $source, 'retrieved_at' => $retrieved, 'recorded_at' => now()]);
        });
    }
}
