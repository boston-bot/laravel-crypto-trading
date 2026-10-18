<?php

namespace App\Services\PaperTrading;

use App\Models\McpConnection;
use App\Models\PaperTrading\DecisionRun;
use App\Models\PaperTrading\PolicyApplication;
use App\Models\PaperTrading\Portfolio;
use App\Models\User;
use App\Services\Monitoring\AccountScope;
use App\Services\Monitoring\IncidentRecorder;
use App\Services\Monitoring\MarketCalendar;
use App\Services\Research\ModelRequest;
use App\Services\Research\ModelResponse;
use App\Services\Research\ResearchBudget;
use App\Services\Research\ResearchError;
use App\Services\Research\ResearchFailure;
use App\Services\Research\ResearchModel;
use App\Support\Mcp\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/** One paid attempt per logical run. No orders, fills, policy edits or broker operations exist here. */
final class DecisionRunner
{
    public function __construct(private ResearchModel $model, private ResearchBudget $budget, private DecisionInputs $inputs, private MarketCalendar $calendar, private IncidentRecorder $incidents) {}

    public function process(string $id): ?DecisionRun
    {
        $run = DB::transaction(function () use ($id) {
            $found = DecisionRun::find($id);
            if (! $found) {
                return null;
            }
            $connection = McpConnection::whereKey($found->mcp_connection_id)->lockForUpdate()->firstOrFail();
            $run = DecisionRun::whereKey($id)->lockForUpdate()->firstOrFail();
            if ($run->status !== 'queued') {
                return null;
            }
            if ($reason = $this->invalidated($connection, $run)) {
                $run->update(['status' => 'superseded', 'reason' => $reason, 'finished_at' => now()]);

                return null;
            }
            if ($reason = DecisionScheduler::readiness()) {
                $run->update(['status' => 'unavailable', 'reason' => $reason, 'finished_at' => now()]);

                return null;
            }
            if ($run->expires_at->lte(now()) || $this->calendar->at(CarbonImmutable::now())['status'] !== 'regular') {
                $run->update(['status' => 'expired', 'reason' => 'decision_window_closed', 'finished_at' => now()]);

                return null;
            }
            if (DecisionRun::where('active_portfolio_id', $run->paper_portfolio_id)->exists()) {
                return null;
            }
            $run->update(['status' => 'running', 'claim' => (string) Str::uuid(), 'active_portfolio_id' => $run->paper_portfolio_id,
                'started_at' => now(), 'lease_until' => now()->addSeconds((int) config('paper.lease_seconds'))]);

            return $run;
        });
        if (! $run) {
            return DecisionRun::find($id);
        }
        $response = null;
        $result = null;
        $reason = null;
        $outcome = null;
        try {
            $connection = McpConnection::findOrFail($run->mcp_connection_id);
            $application = PolicyApplication::findOrFail($run->policy_application_id);
            $portfolio = Portfolio::findOrFail($run->paper_portfolio_id);
            $requested = CarbonImmutable::now();
            $quoteMaxAge = (int) config('paper.quote_max_age_seconds');
            $inputs = $this->inputs->load($connection, $requested);
            if (! is_array($inputs['subjects'] ?? null) || count($inputs['subjects']) < 1 || count($inputs['subjects']) > (int) config('paper.max_symbols') ||
                count($inputs['sources'] ?? []) > (int) config('paper.max_sources')) {
                throw new DecisionFailure('input_bounds_exceeded', 'unavailable');
            }
            $payload = ['requested_at' => $requested->utc()->format(DATE_ATOM), 'expires_at' => $run->expires_at->utc()->format(DATE_ATOM),
                'proposal_guards' => ['quote_max_age_seconds' => $quoteMaxAge, 'executable' => false],
                'paper_policy' => $application->snapshot + ['policy_revision' => $application->revision],
                'paper_portfolio' => ['ledger_version' => $run->ledger_version, 'cash' => $portfolio->cash, 'reserved_cash' => $portfolio->reserved_cash,
                    'positions' => $inputs['positions'], 'positions_status' => $inputs['positions_status']],
                'coverage' => $inputs['coverage'], 'subjects' => $inputs['subjects'], 'sources' => $inputs['sources']];
            $request = new ModelRequest((string) config('research.model'), DecisionSchema::instructions(),
                '<untrusted_data>'.json_encode($payload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).'</untrusted_data>',
                DecisionSchema::schema(), 'paper_decision_v1', (int) config('research.max_output_tokens'));
            if ($request->inputBytes() > (int) config('paper.max_input_bytes')) {
                throw new DecisionFailure('input_bounds_exceeded', 'unavailable');
            }
            DB::transaction(function () use ($run, $request, $payload, $inputs, $application) {
                $connection = McpConnection::whereKey($run->mcp_connection_id)->lockForUpdate()->firstOrFail();
                $owned = DecisionRun::whereKey($run->id)->where('claim', $run->claim)->where('status', 'running')->lockForUpdate()->first();
                if (! $owned || $owned->lease_until->lte(now()) || $owned->expires_at->lte(now())) {
                    throw new DecisionFailure('decision_expired', 'expired');
                }
                if (($reason = $this->invalidated($connection, $owned)) || ($reason = DecisionScheduler::readiness())) {
                    throw new DecisionFailure($reason, 'superseded');
                }
                $prices = ResearchBudget::config();
                $estimate = $this->budget->estimate($request);
                $period = ResearchBudget::period();
                if ($this->budget->reserve($period, $estimate, true, Decimal::from($application->snapshot['rules']['monthly_budget']))) {
                    $this->incidents->record($connection, 'paper_ai_budget_reduced', 'warning', 'paper');
                }
                $owned->update(['model' => $request->model, 'prompt_version' => DecisionSchema::VERSION, 'context_hash' => hash('sha256', $request->input),
                    'request_snapshot' => ['model' => $request->model, 'max_output_tokens' => $request->maxOutputTokens, 'schema_name' => $request->schemaName,
                        'instructions' => $request->instructions, 'input' => $payload, 'schema' => $request->schema, 'source_map' => $inputs['source_map']],
                    'price_basis' => ['input_rate' => (string) $prices['input_rate'], 'output_rate' => (string) $prices['output_rate']],
                    'budget_period' => $period, 'reserved_cost' => (string) $estimate, 'call_started_at' => now()]);
            });
            // No transaction or connection-row lock is held across an external model call.
            $response = $this->model->complete($request);
            if ($response->status !== 'completed') {
                throw new DecisionFailure($response->status === 'refused' ? 'model_refused' : 'model_incomplete', 'failed');
            }
            $result = DecisionSchema::validate($response->text, $inputs, $application->snapshot['rules'], $requested, $run->expires_at, $quoteMaxAge);
            $outcome = collect($result['decisions'])->every(fn ($d) => in_array($d['action'], ['WAIT', 'HOLD'], true)) ? 'wait' : 'proposed';
        } catch (DecisionFailure $error) {
            $reason = $error->reason;
            $outcome = $error->outcome;
        } catch (ResearchFailure $error) {
            $reason = $error->reason->value;
            $outcome = in_array($error->reason, [ResearchError::BudgetExhausted, ResearchError::BudgetReduced], true) ? 'unavailable' : 'failed';
        } catch (Throwable) {
            $reason = 'paper_decision_failed'; // Never persist exception text containing upstream content or credentials.
            $outcome = 'failed';
        }

        return $this->finish($run, $response, $result, $outcome, $reason);
    }

    private function invalidated(McpConnection $connection, DecisionRun $run): ?string
    {
        if (! $connection->bound_account_id || AccountScope::key($connection) !== $run->scope || $connection->status !== 'connected' ||
            $connection->auth_generation !== $run->auth_generation || ! User::whereKey($connection->user_id)->whereIn('email', config('mcp.operator_emails'))->exists()) {
            return 'account_authorization_changed';
        }
        $portfolio = Portfolio::findOrFail($run->paper_portfolio_id);
        $application = PolicyApplication::findOrFail($run->policy_application_id);
        if ($portfolio->mcp_connection_id !== $connection->id || $portfolio->scope !== $run->scope || $application->paper_portfolio_id !== $portfolio->id) {
            return 'paper_scope_inconsistent';
        }
        if ($portfolio->policy_revision !== $application->revision || $portfolio->ledger_version !== $run->ledger_version) {
            return 'policy_or_ledger_changed';
        }

        return null;
    }

    private function finish(DecisionRun $claimed, ?ModelResponse $response, ?array $result, string $outcome, ?string $reason): DecisionRun
    {
        return DB::transaction(function () use ($claimed, $response, $result, $outcome, $reason) {
            $connection = McpConnection::whereKey($claimed->mcp_connection_id)->lockForUpdate()->firstOrFail();
            $run = DecisionRun::whereKey($claimed->id)->lockForUpdate()->firstOrFail();
            $evidence = $response ? ['reported_model' => $response->model, 'response_status' => $response->status,
                'raw_output' => $response->text === null ? null : mb_strcut($response->text, 0, 32768, 'UTF-8'),
                'raw_output_truncated' => $response->text !== null && strlen($response->text) > 32768,
                'validated' => $result, 'executable' => false] : null;
            if ($run->status !== 'running' || $run->claim !== $claimed->claim) {
                if ($run->claim === $claimed->claim && $evidence && $run->result === null) {
                    $run->update(['result' => $evidence + ['late_response' => true], 'response_received_at' => now()]);
                }

                return $run;
            }
            $billing = $this->settle($run, $response, $reason);
            if ($outcome !== 'abandoned') {
                if ($invalid = $this->invalidated($connection, $run)) {
                    $outcome = 'superseded';
                    $reason = $invalid;
                } elseif (! config('paper.decisions_enabled') || ! config('research.enabled')) {
                    $outcome = 'superseded';
                    $reason = 'decisions_disabled';
                } elseif ($run->expires_at->lte(now()) || $this->calendar->at(CarbonImmutable::now())['status'] !== 'regular' || $run->lease_until->lte(now())) {
                    $outcome = 'expired';
                    $reason = 'decision_expired';
                }
            }
            $run->update($billing + ['status' => $outcome, 'reason' => $reason, 'result' => $evidence, 'finished_at' => now(),
                'response_received_at' => $response ? now() : null, 'active_portfolio_id' => null, 'lease_until' => null]);
            if (in_array($reason, ['budget_reduced', 'budget_exhausted'], true) && $this->invalidated($connection, $run) === null) {
                $this->incidents->record($connection, 'paper_ai_'.$reason, 'warning', 'paper');
            }

            return $run;
        });
    }

    private function settle(DecisionRun $run, ?ModelResponse $response, ?string $reason): array
    {
        if (! $run->call_started_at) {
            return ['cost_basis' => 'no_call'];
        }
        $reserved = Decimal::from($run->reserved_cost);
        $unbilled = in_array($reason, ['model_authentication_failed', 'model_request_rejected', 'model_rate_limited'], true);
        $known = $response && $response->inputTokens !== null && $response->inputTokens >= 0 && $response->outputTokens !== null && $response->outputTokens >= 0;
        $prices = $run->price_basis;
        $actual = $unbilled ? Decimal::from('0') : ($known
            ? Decimal::from((string) $response->inputTokens)->multipliedBy(Decimal::from($prices['input_rate']))->plus(Decimal::from((string) $response->outputTokens)->multipliedBy(Decimal::from($prices['output_rate'])))->dividedBy(Decimal::from('1000000'), 18)
            : $reserved);
        $this->budget->settle($run->budget_period, $reserved, $actual);

        return ['reserved_cost' => '0', 'actual_cost' => (string) $actual, 'input_tokens' => $response?->inputTokens, 'output_tokens' => $response?->outputTokens,
            'cost_basis' => $unbilled ? 'provider_rejected' : ($known ? 'provider_usage' : 'reservation_unreconciled')];
    }

    /** Expired work is recorded, never replayed. A lost paid response is conservatively charged in full. */
    public function sweep(): int
    {
        $count = DecisionRun::where('status', 'queued')->where('expires_at', '<=', now())->update(['status' => 'expired', 'reason' => 'queued_window_expired', 'finished_at' => now()]);
        foreach (DecisionRun::where('status', 'running')->where('lease_until', '<=', now())->get() as $run) {
            $this->finish($run, null, null, 'abandoned', 'worker_lease_expired');
            $count++;
        }

        return $count;
    }
}
