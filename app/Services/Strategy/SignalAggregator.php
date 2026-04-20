<?php

namespace App\Services\Strategy;

use App\Enums\OrderSide;
use App\Enums\TradeDecisionAction;
use App\Models\Asset;
use App\Models\MarketQuote;
use App\Models\Position;

class SignalAggregator
{
    public function __construct(
        private readonly MarketRankService $marketRankService,
        private readonly TaSignalService $taSignalService,
        private readonly EntryRuleService $entryRuleService,
        private readonly ExitRuleService $exitRuleService,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function evaluate(Asset $asset): array
    {
        $symbol = strtoupper($asset->symbol);
        $marketRank = $this->marketRankService->rank($symbol);
        $ta = $this->taSignalService->analyze($symbol);

        $existingPosition = Position::query()
            ->where('asset_id', $asset->id)
            ->where('quantity', '>', 0)
            ->exists();

        $entry = ! $existingPosition && $this->entryRuleService->shouldEnter($ta, $marketRank);
        $exit = $existingPosition && $this->exitRuleService->shouldExit($ta, $marketRank);

        $score = round((float) ($marketRank['composite_score'] ?? $marketRank['rank_score'] ?? 0.0), 4);
        $confidence = round((float) ($marketRank['probability_tier']['probability'] ?? $ta['confidence'] ?? 0.5), 4);

        $latestQuote = MarketQuote::query()
            ->where('asset_id', $asset->id)
            ->latest('snapshot_time')
            ->first();
        $referencePrice = (float) ($ta['last_price']
            ?? $latestQuote?->mid_price
            ?? $latestQuote?->last_price
            ?? $latestQuote?->ask_price
            ?? 0.0);

        $decision = TradeDecisionAction::HOLD;
        $side = null;

        if ($entry) {
            $decision = TradeDecisionAction::BUY;
            $side = OrderSide::BUY;
        } elseif ($exit) {
            $decision = TradeDecisionAction::SELL;
            $side = OrderSide::SELL;
        }

        return [
            'decision' => $decision,
            'side' => $side,
            'score' => $score,
            'confidence' => $confidence,
            'market_context' => [
                'market_rank' => $marketRank,
                'regime' => $marketRank['regime'] ?? ['state' => $ta['regime_state'] ?? 'neutral'],
                'reference_price' => round(max(0.0, $referencePrice), 8),
                'quote' => $latestQuote?->only([
                    'snapshot_time',
                    'bid_price',
                    'ask_price',
                    'mid_price',
                    'last_price',
                    'spread_bps',
                    'slippage_bps_estimate',
                ]),
                'skills' => ['strategy-v1'],
            ],
            'signal_context' => [
                'ta' => $ta,
                'scoring' => [
                    'score_tier' => $marketRank['score_tier'] ?? 'E',
                    'probability_tier' => $marketRank['probability_tier']['tier'] ?? 'very_low',
                    'probability' => $marketRank['probability_tier']['probability'] ?? $confidence,
                    'universe_rank' => $marketRank['universe_rank'] ?? null,
                    'universe_size' => $marketRank['universe_size'] ?? 0,
                ],
                'entry_conditions_passed' => $entry,
                'exit_conditions_passed' => $exit,
                'skills' => ['strategy-v1', 'backtesting-trading-strategies'],
            ],
        ];
    }
}
