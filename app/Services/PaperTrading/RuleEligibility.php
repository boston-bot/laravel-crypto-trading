<?php

namespace App\Services\PaperTrading;

use App\Models\McpConnection;
use App\Models\PaperTrading\EligibilityAssessment;
use App\Models\PaperTrading\Portfolio;
use App\Models\User;
use App\Services\Broker\Agentic\TrustedHttp;
use App\Services\Monitoring\AccountScope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/** Deterministic stored-data assessment. Missing adapters stay unknown; no network, AI or ledger mutation. */
final class RuleEligibility
{
    public function inspect(McpConnection $connection, string $symbol, CarbonImmutable $cutoff): array
    {
        $unknown = fn (string $reason) => ['status' => 'unavailable', 'reason' => $reason];
        $portfolio = Portfolio::where('mcp_connection_id', $connection->id)->where('scope', AccountScope::key($connection))->first();
        $policy = $portfolio ? RuleEligibilityPolicies::current($portfolio, $cutoff) : null;
        $execution = $portfolio ? ExecutionPolicies::current($portfolio) : null;
        if ($execution && CarbonImmutable::instance($execution->created_at)->gt($cutoff)) {
            $execution = null; // Never borrow later execution settings for an earlier cutoff.
        }
        $membership = app(StrategyWatchlists::class)->nominations($connection, $cutoff);
        $input = app(PaperMarketInputs::class)->latest($connection, $symbol, $cutoff);
        $checks = ['policy' => $policy && $execution && ($policy->payload['rule_version'] ?? null) === EligibilityRules::VERSION && config('paper.rule_eligibility_enabled') ? ['status' => 'pass', 'reason' => 'rule_policy_selected'] : $unknown('rule_eligibility_disabled_or_unapplied'),
            'provenance' => $unknown('current_authorization_broker_input_unavailable'),
            'listing' => $unknown('us_exchange_listing_unverified'),
            'membership' => in_array($symbol, app(SymbolUniverse::class)->candidates($connection, $cutoff), true) ? ['status' => 'pass', 'reason' => 'current_watchlist_member'] : $unknown('watchlist_not_fresh_or_symbol_removed'),
            'entry_safety' => ! config('mcp.external_order_detection_enabled') ? $unknown('external_order_detection_disabled') : ($membership['entry_pause_reason'] === null ? ['status' => 'pass', 'reason' => 'no_external_order_pause'] : $unknown($membership['entry_pause_reason'])),
            'classification' => $unknown('market_input_unavailable'), 'tradability' => $unknown('market_input_unavailable'), 'corporate_actions' => $unknown('market_input_unavailable')];
        $quote = null;
        $evidence = ['policy_id' => $policy?->id, 'execution_version_id' => $execution?->id, 'watchlist_revision_id' => $membership['revision']?->id,
            'account_scope' => AccountScope::key($connection), 'auth_generation' => $connection->auth_generation,
            'market_input_id' => $input?->id, 'classification_id' => null, 'cutoff' => $cutoff->toISOString(),
            'history_source' => 'unconfigured', 'listing_read_id' => null, 'history_read_id' => null,
            'quote_max_age_seconds' => $execution?->payload['quote_max_age_seconds']];
        // Reviewed listing and daily-history reads. Only stored, complete, recent records count; nothing else can supply them.
        $references = app(PaperMarketReferences::class);
        $listing = $references->listing($connection, $symbol, $cutoff);
        $checks['listing'] = array_intersect_key($listing, array_flip(['status', 'reason', 'exchange']));
        $evidence['listing_read_id'] = $listing['read_id'] ?? null;
        $history = $references->history($connection, $symbol, $cutoff);
        $evidence['history_read_id'] = $history['read_id'] ?? null;
        if ($history['verified']) {
            $evidence['history_source'] = $history['contract'];
        }
        $maxAge = $execution?->payload['quote_max_age_seconds'] ?? 20;
        if ($input && $input->origin === 'broker_read' && ($input->payload['provider'] ?? null) === TrustedHttp::RESOURCE &&
            ($input->payload['auth_generation'] ?? null) === $connection->auth_generation &&
            ($input->payload['complete'] ?? false) === true && ($input->payload['quote']['symbol'] ?? null) === $symbol) {
            $checks['provenance'] = ['status' => 'pass', 'reason' => 'scoped_broker_input_current_authorization'];
            $class = app(InstrumentEligibility::class)->inspect($input, $cutoff, $maxAge, false);
            $checks['classification'] = ['status' => $class['eligible'] === true ? 'pass' : ($class['eligible'] === false ? 'ineligible' : 'unavailable'),
                'reason' => $class['reason'] ?? 'stock_etf_not_leveraged_or_inverse', 'evidence' => $class];
            $evidence['classification_id'] = $class['classification_id'];
            $actions = app(CorporateActionClearance::class)->inspect($input, $cutoff, $maxAge);
            $checks['corporate_actions'] = ['status' => $actions['clear'] === true ? 'pass' : 'unavailable', 'reason' => $actions['reason'] ?? 'observed_actions_reconciled', 'evidence' => $actions];
            $trade = $input->payload['execution_reads']['tradability'] ?? [];
            if (($trade['provider'] ?? null) === TrustedHttp::RESOURCE && ($trade['complete'] ?? false) === true &&
                ($trade['fields']['symbol'] ?? null) === $symbol && ($trade['contract_fingerprint'] ?? null) === config('robinhood_mcp.operations.tradability.fingerprint') &&
                $this->fresh($trade['retrieved_at'] ?? null, $cutoff, $input->retrieved_at, $maxAge)) {
                $value = $trade['fields']['broker_tradeable'] ?? null;
                $checks['tradability'] = ['status' => $value === true ? 'pass' : ($value === false ? 'ineligible' : 'unavailable'),
                    'reason' => $value === true ? 'account_tradable' : ($value === false ? 'account_not_tradable' : 'account_tradability_unknown')];
            } else {
                $checks['tradability'] = $unknown('account_tradability_unverified_or_stale');
            }
            $q = $input->payload['quote'];
            if ($this->fresh($q['retrieved_at'] ?? null, $cutoff, $input->retrieved_at, $maxAge) &&
                $this->fresh($q['bid_as_of'] ?? null, $cutoff, CarbonImmutable::parse($q['retrieved_at']), $maxAge) &&
                $this->fresh($q['ask_as_of'] ?? null, $cutoff, CarbonImmutable::parse($q['retrieved_at']), $maxAge)) {
                $quote = $q;
            }
        }
        // Extra market/model fields cannot supply history or volume; only the verified stored read can.
        $checks += app(EligibilityRules::class)->thresholds($quote, $history['verified'] ? $history : null, $policy?->payload['parameters'] ?? RuleEligibilityPolicies::DEFAULTS);
        if (! $history['verified'] && config('alpaca_data.reference_enabled')) {
            $checks['history'] = $checks['liquidity'] = ['status' => 'unavailable', 'reason' => $history['reason']];
        }
        $statuses = array_column($checks, 'status');
        $status = in_array('ineligible', $statuses, true) ? 'ineligible' : (in_array('unavailable', $statuses, true) ? 'unavailable' : 'eligible');
        $fingerprint = hash('sha256', json_encode(['rule' => EligibilityRules::VERSION, 'evidence' => $evidence, 'checks' => $checks,
            'parameters' => $policy?->payload['parameters']], JSON_THROW_ON_ERROR));

        return ['status' => $status, 'rule_version' => EligibilityRules::VERSION, 'input_fingerprint' => $fingerprint, 'evidence' => $evidence, 'checks' => $checks];
    }

    public function record(McpConnection $connection, int $actor, string $symbol, string $request, CarbonImmutable $cutoff): EligibilityAssessment
    {
        Validator::make(['symbol' => $symbol, 'request' => $request], ['symbol' => ['required', 'regex:/^[A-Z][A-Z0-9.-]{0,15}$/D'], 'request' => ['required', 'uuid']])->validate();

        return DB::transaction(function () use ($connection, $actor, $symbol, $request, $cutoff) {
            $current = McpConnection::whereKey($connection->id)->lockForUpdate()->firstOrFail();
            abort_unless($current->user_id === $actor && User::whereKey($actor)->whereIn('email', config('mcp.operator_emails'))->exists(), 403);
            abort_unless($current->status === 'connected' && $current->account_role === 'agentic_monitoring' && $current->bound_account_id && $connection->bound_account_id &&
                AccountScope::key($current) === AccountScope::key($connection) && $current->auth_generation === $connection->auth_generation, 409, 'Account authorization changed.');
            abort_if($cutoff->isFuture(), 422, 'Future cutoffs are not allowed.');
            $scope = AccountScope::key($current);
            if ($repeat = EligibilityAssessment::where('mcp_connection_id', $current->id)->where('scope', $scope)->where('request_id', $request)->where('symbol', $symbol)->first()) {
                abort_unless($repeat->cutoff->eq($cutoff), 409, 'Request already used with a different cutoff.');

                return $repeat;
            }
            $report = $this->inspect($current, $symbol, $cutoff);
            $report['actor_id'] = $actor;
            abort_unless($report['evidence']['policy_id'] && $report['evidence']['execution_version_id'], 409, 'Apply a rule eligibility policy and execution settings first.');

            return EligibilityAssessment::create(['mcp_connection_id' => $current->id, 'scope' => $scope, 'auth_generation' => $current->auth_generation,
                'policy_id' => $report['evidence']['policy_id'], 'execution_version_id' => $report['evidence']['execution_version_id'],
                'watchlist_revision_id' => $report['evidence']['watchlist_revision_id'], 'market_input_id' => $report['evidence']['market_input_id'],
                'symbol' => $symbol, 'request_id' => $request, 'rule_version' => $report['rule_version'], 'input_fingerprint' => $report['input_fingerprint'],
                'status' => $report['status'], 'payload' => $report, 'cutoff' => $cutoff, 'recorded_at' => now()]);
        });
    }

    private function fresh(mixed $time, CarbonImmutable $cutoff, CarbonImmutable $available, int $age): bool
    {
        if (! is_string($time) || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/D', $time)) {
            return false;
        }
        try {
            $at = CarbonImmutable::parse($time);

            return $at->lte($cutoff) && $at->lte($available) && $at->gte($cutoff->subSeconds($age));
        } catch (\InvalidArgumentException) {
            return false;
        }
    }
}
