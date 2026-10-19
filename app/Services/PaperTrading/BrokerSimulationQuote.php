<?php

declare(strict_types=1);

namespace App\Services\PaperTrading;

use App\Models\McpConnection;
use App\Models\PaperTrading\MarketInput;
use App\Services\Broker\Agentic\TrustedHttp;
use App\Services\Monitoring\AccountScope;
use Carbon\CarbonImmutable;

/** Paper-only observation from an immutable, account-scoped broker input. Never an executable broker order. */
final readonly class BrokerSimulationQuote
{
    public array $data;

    private function __construct(private SimulationQuote $validated, public int $inputId, private int $connectionId, private string $scope, private int $authGeneration, array $evidence)
    {
        $this->data = array_replace($validated->data, ['provenance' => 'robinhood_paper_observation', 'market_input_id' => $inputId,
            'provider' => TrustedHttp::RESOURCE, 'liquidity_key' => $evidence['liquidity_key'], 'evidence' => $evidence]);
    }

    public static function fromStored(McpConnection $connection, int $inputId, CarbonImmutable $cutoff, int $maxAge): self
    {
        abort_unless(config('paper.real_data_fills_enabled'), 409, 'Real-data paper fills are disabled.');
        $input = MarketInput::whereKey($inputId)->where('mcp_connection_id', $connection->id)->where('scope', AccountScope::key($connection))->firstOrFail();
        abort_unless($input->origin === 'broker_read' && ($input->payload['provider'] ?? null) === TrustedHttp::RESOURCE, 409, 'Broker observation provenance is unverified.');
        if ($reason = app(PaperMarketInputs::class)->executionReason($input, $cutoff, $maxAge)) {
            throw new DecisionFailure($reason, 'unavailable');
        }
        $match = app(BookQuoteMatcher::class)->match($input->payload, $cutoff, $maxAge);
        if ($match['status'] !== 'matched') {
            throw new DecisionFailure($match['reason'], 'unavailable');
        }
        $q = $input->payload['quote'];
        $actionPolicy = null;
        if (config('paper.corporate_actions_enabled')) {
            $actionPolicy = app(CorporateActionClearance::class)->inspect($input, $cutoff, $maxAge);
            $q['corporate_actions_clear'] = $actionPolicy['clear'];
        }
        $classPolicy = null;
        if (config('paper.instrument_classification_enabled')) {
            $classPolicy = app(InstrumentEligibility::class)->inspect($input, $cutoff, $maxAge);
            $q = array_replace($q, array_intersect_key($classPolicy, array_flip(['eligible', 'asset_type', 'leveraged', 'inverse'])));
        }
        abort_unless(($q['symbol'] ?? null) === $input->symbol, 409, 'Broker observation symbol differs from its stored identity.');
        $trade = $input->payload['execution_reads']['tradability'] ?? [];
        $time = $trade['retrieved_at'] ?? null;
        $book = $input->payload['execution_reads']['price_book'];
        try {
            if (! is_string($time) || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/D', $time)) {
                throw new \InvalidArgumentException('Unknown tradability retrieval time.');
            }
            $tradeTime = CarbonImmutable::parse($time);
        } catch (\InvalidArgumentException) {
            throw new DecisionFailure('broker_tradability_unverified', 'unavailable');
        }
        if (($trade['provider'] ?? null) !== TrustedHttp::RESOURCE || ($trade['complete'] ?? false) !== true ||
            ($trade['fields']['symbol'] ?? null) !== $input->symbol || ($trade['fields']['broker_tradeable'] ?? null) !== true ||
            $tradeTime->gt($cutoff) || $tradeTime->gt($input->retrieved_at) || $tradeTime->lt($cutoff->subSeconds($maxAge))) {
            throw new DecisionFailure('broker_tradability_unverified', 'unavailable');
        }
        if (CarbonImmutable::parse($book['retrieved_at'])->gt($input->retrieved_at)) {
            throw new DecisionFailure('book_local_availability_unverified', 'unavailable');
        }
        // Re-polling one book snapshot must never replenish its displayed liquidity.
        $liquidityKey = hash('sha256', TrustedHttp::RESOURCE.':'.$input->symbol.':'.CarbonImmutable::parse($book['source_as_of'])->utc()->format('Y-m-d\\TH:i:s.uP'));
        $source = CarbonImmutable::parse($q['bid_as_of'])->min(CarbonImmutable::parse($q['ask_as_of']))->min(CarbonImmutable::parse($book['source_as_of']));
        $quote = new SimulationQuote(array_intersect_key($q, array_flip(['symbol', 'bid', 'ask', 'eligible', 'asset_type', 'leveraged', 'inverse', 'corporate_actions_clear'])) +
            ['key' => 'broker-book:'.$liquidityKey, 'provenance' => 'synthetic', 'source_at' => $source->utc()->toIso8601String(),
                'retrieved_at' => $input->retrieved_at->toIso8601String(), 'bid_size' => $match['bid_size'], 'ask_size' => $match['ask_size'],
                // Current reviewed broker contract does not establish fractional LIMIT permission. Whole shares only.
                'fractional' => null]);

        return new self($quote, $input->id, $connection->id, AccountScope::key($connection), $connection->auth_generation,
            ['liquidity_key' => $liquidityKey, 'recorded_at' => $input->recorded_at->toISOString(), 'market_input_source_at' => $input->source_at?->toISOString(),
                'corporate_action_policy' => $actionPolicy, 'classification_policy' => $classPolicy, 'quote_retrieved_at' => $q['retrieved_at'], 'tradability_retrieved_at' => $time, 'quote_times' => [$q['bid_as_of'], $q['ask_as_of']],
                'book_source_at' => $book['source_as_of'], 'book_retrieved_at' => $book['retrieved_at'], 'semantic_fingerprint' => $book['semantic_fingerprint']]);
    }

    public function assertCurrent(McpConnection $connection, int $maxAge): void
    {
        abort_unless($connection->id === $this->connectionId && AccountScope::key($connection) === $this->scope &&
            $connection->auth_generation === $this->authGeneration, 409, 'Broker observation authorization changed.');
        $latest = app(PaperMarketInputs::class)->latest($connection, $this->data['symbol'], CarbonImmutable::now());
        if ($latest?->id !== $this->inputId) {
            throw new DecisionFailure('broker_observation_superseded', 'unavailable');
        }
        // Recheck stored evidence and current semantic pin at the locked execution boundary.
        self::fromStored($connection, $this->inputId, CarbonImmutable::now(), $maxAge);
    }

    public function reason(CarbonImmutable $now, int $maxAge): ?string
    {
        return $this->validated->reason($now, $maxAge);
    }
}
