<?php

namespace App\Services\Risk;

use App\Data\Trading\PolicyEvaluation;
use App\Data\Trading\TradeCandidate;
use App\Enums\TradingDecisionStatus;
use App\Models\Asset;
use App\Models\TradeDecision;
use Illuminate\Support\Facades\Cache;

class PolicyEngine
{
    public function evaluate(TradeCandidate $candidate, Asset $asset): PolicyEvaluation
    {
        $checks = [];
        $messages = [];

        $mode = (string) config('broker.mode', 'paper');
        $requiresHumanApproval = $mode === 'live'
            && (bool) config('trading.human_approval_required', true);

        $checks['kill_switch'] = ! Cache::get((string) config('trading.kill_switch_cache_key', 'trading:frozen'), false);
        if (! $checks['kill_switch']) {
            $messages['kill_switch'] = 'Trading kill switch is active.';
        }

        $checks['allowed_asset'] = in_array(
            strtoupper($asset->symbol),
            array_map('strtoupper', (array) config('trading.allowed_assets', [])),
            true
        );
        if (! $checks['allowed_asset']) {
            $messages['allowed_asset'] = 'Asset is not in the trading allowlist.';
        }

        $checks['trading_enabled'] = $mode === 'paper' || (bool) config('trading.enabled');
        if (! $checks['trading_enabled']) {
            $messages['trading_enabled'] = 'Live trading is disabled by configuration.';
        }

        $checks['cooldown'] = ! $this->isInCooldown($candidate->assetId);
        if (! $checks['cooldown']) {
            $messages['cooldown'] = 'Trade cooldown is active for this asset.';
        }

        return new PolicyEvaluation(
            passed: ! in_array(false, $checks, true),
            requiresHumanApproval: $requiresHumanApproval,
            checks: $checks,
            messages: $messages,
            context: [
                'mode' => $mode,
            ],
        );
    }

    private function isInCooldown(int $assetId): bool
    {
        $cooldownMinutes = (int) config('trading.cooldown_minutes', 30);
        if ($cooldownMinutes <= 0) {
            return false;
        }

        return TradeDecision::query()
            ->where('asset_id', $assetId)
            ->whereIn('status', [
                TradingDecisionStatus::APPROVED->value,
                TradingDecisionStatus::SUBMITTED->value,
                TradingDecisionStatus::PARTIALLY_FILLED->value,
                TradingDecisionStatus::FILLED->value,
            ])
            ->where('created_at', '>=', now()->subMinutes($cooldownMinutes))
            ->exists();
    }
}
