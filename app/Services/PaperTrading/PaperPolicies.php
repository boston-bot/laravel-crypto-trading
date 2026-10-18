<?php

namespace App\Services\PaperTrading;

use App\Models\McpConnection;
use App\Models\PaperTrading\CashEntry;
use App\Models\PaperTrading\MandateVersion;
use App\Models\PaperTrading\PolicyApplication;
use App\Models\PaperTrading\Portfolio;
use App\Services\Monitoring\AccountScope;
use App\Support\Mcp\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Applies only to the separate virtual ledger. No scheduler, AI or broker operation is dispatched here. */
final class PaperPolicies
{
    public function __construct(private PaperMandates $mandates) {}

    public function apply(McpConnection $connection, int $actor, int $mandateId, int $baseDraft, int $basePolicy, string $requestId): PolicyApplication
    {
        return DB::transaction(function () use ($connection, $actor, $mandateId, $baseDraft, $basePolicy, $requestId) {
            $current = McpConnection::whereKey($connection->id)->lockForUpdate()->firstOrFail();
            abort_unless($current->user_id === $actor && $current->bound_account_id && AccountScope::key($current) === AccountScope::key($connection), 409, 'Account binding changed. Reload the control center.');
            $scope = AccountScope::key($current);
            $versions = MandateVersion::where('mcp_connection_id', $current->id)->where('scope', $scope);
            $mandate = (clone $versions)->whereKey($mandateId)->firstOrFail();
            $portfolio = Portfolio::where('mcp_connection_id', $current->id)->where('scope', $scope)->lockForUpdate()->first();
            $repeat = $portfolio ? PolicyApplication::where('paper_portfolio_id', $portfolio->id)->where('request_id', $requestId)->first() : null;
            if ($repeat) {
                abort_unless($repeat->mandate_version_id === $mandate->id, 409, 'This application request was already used for another draft.');

                return $repeat;
            }
            $latest = (clone $versions)->latest('version')->first();
            abort_unless($latest->id === $mandate->id && $mandate->version === $baseDraft && ($portfolio?->policy_revision ?? 0) === $basePolicy,
                409, 'Draft or applied paper policy changed. Reload and review before applying.');
            $payload = $this->mandates->validate($mandate->payload);
            foreach (array_diff(PaperMandates::FIELDS, ['ai_guidance']) as $field) {
                if ($payload[$field] === null) {
                    throw ValidationException::withMessages([$field => 'Complete all paper capital, allocation, limit, budget and cadence fields before applying.']);
                }
            }
            if ($portfolio && Decimal::from($portfolio->starting_cash)->compareTo(Decimal::from($payload['initial_cash'])) !== 0) {
                throw ValidationException::withMessages(['initial_cash' => 'Starting cash cannot be changed by applying a policy. Preserve the existing virtual ledger.']);
            }
            if (! $portfolio) {
                $portfolio = Portfolio::create(['mcp_connection_id' => $current->id, 'scope' => $scope,
                    'starting_cash' => $payload['initial_cash'], 'cash' => $payload['initial_cash'], 'reserved_cash' => '0',
                    'ledger_version' => 1, 'policy_revision' => 1, 'created_at' => now()]);
                CashEntry::create(['paper_portfolio_id' => $portfolio->id, 'actor_id' => $actor, 'reference' => 'opening:'.$portfolio->id,
                    'kind' => 'opening_balance', 'amount' => $payload['initial_cash'], 'balance_after' => $payload['initial_cash'],
                    'ledger_version' => 1, 'recorded_at' => now()]);
            } else {
                $portfolio->update(['policy_revision' => $basePolicy + 1]);
            }

            return PolicyApplication::create(['paper_portfolio_id' => $portfolio->id, 'mandate_version_id' => $mandate->id,
                'actor_id' => $actor, 'revision' => $portfolio->policy_revision, 'request_id' => $requestId,
                'snapshot' => ['mode' => 'paper', 'mandate_version' => $mandate->version, 'rules' => $payload,
                    'horizon' => 'days_to_weeks', 'discovery_universe' => 'broad_us_stocks_etfs', 'execution_eligibility' => 'unverified'],
                'applied_at' => now()]);
        });
    }

    /** One consistent local view for the browser and future decision context. Never re-label broker cash as paper cash. */
    public function state(McpConnection $connection): ?array
    {
        return DB::transaction(function () use ($connection) {
            $current = McpConnection::whereKey($connection->id)->lockForUpdate()->firstOrFail();
            if (! $current->bound_account_id || AccountScope::key($current) !== AccountScope::key($connection)) {
                return null;
            }
            $portfolio = Portfolio::where('mcp_connection_id', $current->id)->where('scope', AccountScope::key($current))->first();
            if (! $portfolio) {
                return null;
            }
            $application = PolicyApplication::where('paper_portfolio_id', $portfolio->id)->where('revision', $portfolio->policy_revision)->firstOrFail();

            return ['portfolio' => $portfolio, 'application' => $application,
                'available_cash' => (string) Decimal::from($portfolio->cash)->minus(Decimal::from($portfolio->reserved_cash)),
                'cash_entries' => CashEntry::where('paper_portfolio_id', $portfolio->id)->orderByDesc('ledger_version')->limit(20)->get()];
        });
    }
}
