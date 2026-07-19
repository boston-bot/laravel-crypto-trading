<?php

namespace App\Services\Execution;

use App\Data\Trading\RevalidationResult;
use App\Data\Trading\TradeCandidate;
use App\Enums\BrokerType;
use App\Enums\OrderSide;
use App\Jobs\SyncCoinbaseAccountJob;
use App\Jobs\SyncCoinbasePositionsJob;
use App\Models\MarketQuote;
use App\Models\TradeDecision;
use App\Services\Broker\BrokerCredentialResolver;
use App\Services\Broker\Coinbase\CoinbaseClient;
use App\Services\MarketData\FeeScheduleService;
use App\Services\MarketData\QuoteSnapshotService;
use App\Services\Portfolio\PortfolioContextResolver;
use App\Services\Risk\PolicyEngine;
use App\Services\Risk\RiskEngine;
use Illuminate\Bus\Dispatcher;
use Throwable;

class ApprovalRevalidationService
{
    public function __construct(
        private readonly Dispatcher $dispatcher,
        private readonly BrokerCredentialResolver $credentials,
        private readonly CoinbaseClient $coinbase,
        private readonly QuoteSnapshotService $quotes,
        private readonly FeeScheduleService $fees,
        private readonly OrderSizingService $sizing,
        private readonly RiskEngine $risk,
        private readonly PolicyEngine $policy,
        private readonly PortfolioContextResolver $portfolioContexts,
    ) {}

    public function refreshAndEvaluate(TradeDecision $decision): RevalidationResult
    {
        $decision->loadMissing(['asset', 'brokerAccount']);
        if ($decision->asset === null || $decision->brokerAccount === null) {
            return new RevalidationResult(false, ['Decision is missing its asset or broker account.']);
        }

        if ((string) config('broker.mode', 'paper') === 'live') {
            try {
                $this->refreshLiveState($decision);
            } catch (Throwable $exception) {
                return new RevalidationResult(false, ['Unable to refresh live Coinbase state: '.$exception->getMessage()]);
            }
            $decision->refresh()->loadMissing(['asset', 'brokerAccount']);
        }

        $expiresAt = $decision->signal_expires_at
            ?? $decision->created_at?->copy()->addMinutes((int) config('research.engine.proposal_ttl_minutes', 30));
        if ($expiresAt === null || $expiresAt->isPast()) {
            return new RevalidationResult(false, ['Proposal has expired.'], ['expired_at' => $expiresAt?->toIso8601String()]);
        }

        $quote = MarketQuote::query()->where('asset_id', $decision->asset_id)->latest('snapshot_time')->first();
        $maxQuoteAge = (int) config('research.approval.max_quote_age_seconds', 15);
        if ($quote === null || $quote->snapshot_time->lt(now()->subSeconds($maxQuoteAge))) {
            return new RevalidationResult(false, ['Fresh executable quote is unavailable.'], [
                'max_quote_age_seconds' => $maxQuoteAge,
                'quote_time' => $quote?->snapshot_time?->toIso8601String(),
            ]);
        }

        $price = (float) ($quote->mid_price ?: $quote->last_price);
        if ($price <= 0) {
            return new RevalidationResult(false, ['Fresh quote does not contain a valid observed price.']);
        }

        $original = (float) data_get($decision->market_context_json, 'reference_price', 0);
        if ($original <= 0) {
            return new RevalidationResult(false, ['Proposal has no observed reference price for drift validation.']);
        }
        $driftBps = abs(($price - $original) / $original) * 10000;
        if ($driftBps > (float) config('research.approval.max_price_change_bps', 75)) {
            return new RevalidationResult(false, ['Price changed materially after proposal creation.'], [
                'original_price' => $original, 'fresh_price' => $price, 'price_change_bps' => round($driftBps, 4),
            ]);
        }

        if ((string) config('broker.mode', 'paper') === 'live' && $this->fees->current('coinbase') === null) {
            return new RevalidationResult(false, ['Coinbase fee snapshot is missing or stale.']);
        }

        $signal = [
            'score' => (float) $decision->score,
            'confidence' => (float) $decision->confidence,
            'market_context' => array_merge((array) $decision->market_context_json, [
                'reference_price' => $price,
                'quote' => [
                    'spread_bps' => (float) $quote->spread_bps,
                    'slippage_bps_estimate' => (float) $quote->slippage_bps_estimate,
                    'snapshot_time' => $quote->snapshot_time->toIso8601String(),
                ],
            ]),
            'signal_context' => (array) $decision->signal_context_json,
        ];
        $mode = (string) config('broker.mode', 'paper');
        $portfolioContext = $this->portfolioContexts->resolve($mode, $decision->brokerAccount);
        $signal['market_context']['portfolio_context'] = $portfolioContext->toPayload();
        $size = $decision->side === OrderSide::SELL
            ? ['quantity' => (float) $decision->requested_quantity, 'notional' => (float) $decision->requested_quantity * $price]
            : $this->sizing->size($portfolioContext, $price, $signal);
        $candidate = new TradeCandidate(
            assetId: $decision->asset_id,
            symbol: $decision->asset->symbol,
            side: $decision->side ?? OrderSide::BUY,
            quantity: (float) $size['quantity'],
            notionalUsd: (float) $size['notional'],
            score: (float) $decision->score,
            confidence: (float) $decision->confidence,
            marketContext: (array) $signal['market_context'],
            signalContext: (array) $signal['signal_context'],
        );
        $risk = $this->risk->evaluate($candidate, $portfolioContext);
        $policy = $this->policy->evaluate($candidate, $decision->asset);
        $reasons = [...$risk->violations, ...array_values($policy->messages)];
        if (! $risk->passed || ! $policy->passed || $candidate->quantity <= 0 || $candidate->notionalUsd <= 0) {
            if ($candidate->quantity <= 0 || $candidate->notionalUsd <= 0) {
                $reasons[] = 'Revalidated order size is zero.';
            }

            return new RevalidationResult(false, array_values(array_unique($reasons)), [
                'price_change_bps' => round($driftBps, 4), 'risk' => $risk->context, 'policy' => $policy->context,
            ]);
        }

        $decision->update([
            'requested_quantity' => $candidate->quantity,
            'requested_notional' => $candidate->notionalUsd,
            'market_context_json' => $candidate->marketContext,
            'risk_context_json' => ['passed' => true, 'violations' => [], 'context' => $risk->context, 'revalidated_at' => now()->toIso8601String()],
            'policy_result_json' => ['passed' => true, 'checks' => $policy->checks, 'messages' => $policy->messages, 'context' => $policy->context, 'revalidated_at' => now()->toIso8601String()],
        ]);

        return new RevalidationResult(true, [], [
            'fresh_price' => $price, 'price_change_bps' => round($driftBps, 4),
            'requested_quantity' => $candidate->quantity, 'requested_notional' => $candidate->notionalUsd,
        ]);
    }

    private function refreshLiveState(TradeDecision $decision): void
    {
        $credential = $this->credentials->resolve(
            BrokerType::COINBASE,
            $decision->brokerAccount?->broker_credential_id !== null ? (int) $decision->brokerAccount->broker_credential_id : null,
        ) ?? $this->credentials->resolve(BrokerType::COINBASE);
        if ($credential === null) {
            throw new \RuntimeException('Active Coinbase credentials are unavailable.');
        }

        $this->dispatcher->dispatchSync(new SyncCoinbaseAccountJob($credential->id));
        $this->dispatcher->dispatchSync(new SyncCoinbasePositionsJob($credential->id));
        $rows = $this->coinbase->getBestBidAsk($credential, [$decision->asset->symbol]);
        $row = $rows[0] ?? null;
        if (! is_array($row)) {
            throw new \RuntimeException('Coinbase returned no quote for '.$decision->asset->symbol.'.');
        }
        $bid = (float) data_get($row, 'bids.0.price', $row['bid'] ?? 0);
        $ask = (float) data_get($row, 'asks.0.price', $row['ask'] ?? 0);
        $this->quotes->store($decision->asset, [
            'bid_price' => $bid, 'ask_price' => $ask,
            'last_price' => (float) ($row['price'] ?? (($bid + $ask) / 2)),
            'snapshot_time' => now(), 'raw' => $row,
        ], 'coinbase');
        $this->fees->refresh($credential);
    }
}
