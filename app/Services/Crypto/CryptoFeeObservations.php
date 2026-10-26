<?php

declare(strict_types=1);

namespace App\Services\Crypto;

use App\Enums\BrokerType;
use App\Models\PaperTrading\CryptoFeeObservation;
use App\Models\PaperTrading\Portfolio;
use App\Models\User;
use App\Services\Broker\BrokerCredentialResolver;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * C3: immutable account fee-tier observations from the fixed read-only GET. An observation becomes a product fee schedule
 * only through an owner tier review in the applied policy, for at most one day. No policy, cash, flag or order change.
 */
final class CryptoFeeObservations
{
    public function __construct(private CoinbaseFeeTier $gateway, private BrokerCredentialResolver $credentials) {}

    public function record(int $actor, ?int $credentialId = null, string $trigger = 'manual'): CryptoFeeObservation
    {
        $user = User::findOrFail($actor);
        abort_unless(in_array($user->email, config('mcp.operator_emails'), true), 403);
        $started = CarbonImmutable::now();
        try {
            $credential = $this->credentials->resolve(BrokerType::COINBASE, $credentialId) ?? throw new \RuntimeException;
            $report = $this->gateway->read($credential);
        } catch (Throwable) {
            // Only the generic failure is stored; the gateway already discards provider bodies and signed requests.
            return CryptoFeeObservation::create(['venue' => 'coinbase', 'actor_id' => $actor, 'trigger' => $trigger, 'status' => 'failed',
                'reasons' => ['fee_tier_read_unavailable'], 'request_started_at' => $started, 'recorded_at' => now()]);
        }

        return CryptoFeeObservation::create(['venue' => 'coinbase', 'actor_id' => $actor, 'trigger' => $trigger, 'status' => 'complete',
            'contract' => $report['contract'], 'pricing_tier' => $report['pricing_tier'], 'maker_rate' => $report['rates']['maker_fee_rate'],
            'taker_rate' => $report['rates']['taker_fee_rate'], 'reasons' => $report['reasons'], 'response_sha256' => $report['response_sha256'],
            'request_started_at' => $report['request_started_at'], 'retrieved_at' => $report['retrieved_at'], 'recorded_at' => now()]);
    }

    /** Scheduled refresh for each operator that owns a crypto sleeve; skipped while a recent observation or attempt exists. */
    public function scheduled(): array
    {
        if (! config('crypto.fee_refresh_enabled')) {
            return ['status' => 'disabled'];
        }
        $results = [];
        $operators = User::whereIn('email', config('mcp.operator_emails'))->pluck('id');
        foreach (Portfolio::where(['asset_class' => 'crypto', 'venue' => 'coinbase'])->whereIn('actor_id', $operators)->pluck('actor_id')->unique() as $actor) {
            $fresh = CryptoFeeObservation::where(['venue' => 'coinbase', 'actor_id' => $actor, 'status' => 'complete'])
                ->where('retrieved_at', '>', now()->subHours((int) config('crypto.fee_refresh_after_hours'))->format('Y-m-d H:i:s.u'))->exists();
            // A failed read retries on a later hourly slot, not in a loop.
            $attempted = CryptoFeeObservation::where(['venue' => 'coinbase', 'actor_id' => $actor])
                ->where('request_started_at', '>', now()->subMinutes(50)->format('Y-m-d H:i:s.u'))->exists();
            $results[$actor] = $fresh || $attempted ? 'fresh' : $this->record((int) $actor, null, 'scheduled')->status;
        }

        return ['status' => 'complete', 'actors' => $results];
    }

    /**
     * Product fee schedule at the cutoff: an explicit unexpired product observation, otherwise the newest tier observation that
     * matches the owner's tier review and is under one day old. Exception pairs and unreviewed tiers yield null (no entry).
     */
    public function schedule(int $actor, array $policy, string $product, CarbonImmutable $cutoff): ?array
    {
        $explicit = $policy['costs'][$product] ?? null;
        if ($explicit !== null) {
            return CarbonImmutable::parse($explicit['observed_at'])->lte($cutoff) && CarbonImmutable::parse($explicit['valid_until'])->gt($cutoff) ? $explicit : null;
        }
        $review = $policy['fee_review'] ?? null;
        if ($review === null || in_array($product, $review['excluded_products'], true)) {
            return null;
        }
        $observation = CryptoFeeObservation::where(['venue' => 'coinbase', 'actor_id' => $actor, 'status' => 'complete'])
            ->where('retrieved_at', '<=', $cutoff->format('Y-m-d H:i:s.u'))->where('recorded_at', '<=', $cutoff->format('Y-m-d H:i:s.u'))
            ->orderByDesc('retrieved_at')->orderByDesc('id')->first();
        if (! $observation || $observation->retrieved_at->lte($cutoff->subSeconds($review['max_observation_age_seconds'])) ||
            $observation->pricing_tier !== $review['pricing_tier'] || array_diff($observation->reasons, $review['accepted_reasons']) !== []) {
            return null;
        }

        return ['kind' => 'venue_notional_rate_v1', 'venue' => 'coinbase', 'version' => 'coinbase_tier_observation_'.$observation->id,
            'product_id' => $product, 'cost_scope' => $review['cost_scope'], 'modeled_fee_role' => $review['modeled_fee_role'],
            'maker_rate' => CryptoMarketCollector::plain((string) $observation->maker_rate), 'taker_rate' => CryptoMarketCollector::plain((string) $observation->taker_rate),
            'observed_at' => $observation->retrieved_at->toISOString(), 'valid_until' => $observation->retrieved_at->addSeconds($review['max_observation_age_seconds'])->toISOString(),
            'source' => 'Coinbase spot tier '.$observation->pricing_tier.' observation '.$observation->id.' sha256 '.$observation->response_sha256.'; owner review '.$review['reviewed_at'],
            'tax_treatment' => $review['tax_treatment']];
    }
}
