<?php

namespace App\Services\Research;

use App\Models\McpConnection;
use App\Models\Research\Event;
use App\Models\Research\Run;
use App\Models\Research\Source;
use App\Services\Monitoring\AccountScope;
use App\Services\Monitoring\IncidentRecorder;
use App\Support\Mcp\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use LogicException;
use Throwable;

/**
 * Processes one research event. No SQL transaction is held during the model call. Results are validated data with
 * no execution path; the model cannot change policy, limits, mode, credentials or broker state.
 */
final class ResearchRunner
{
    public function __construct(private ResearchModel $model, private ResearchBudget $budget, private ResearchContext $context, private IncidentRecorder $incidents) {}

    public function process(int $eventId): ?Run
    {
        $event = Event::findOrFail($eventId);
        if ($reason = ResearchReadiness::reason()) {
            Event::whereKey($eventId)->where('status', 'pending')->update(['status_reason' => $reason->value, 'dispatched_at' => null]);

            return null;
        }
        [$run, $owned, $evidence] = DB::transaction(fn () => $this->claim($event->mcp_connection_id, $eventId));
        if (! $owned) {
            return $run;
        }
        $contextHash = null;
        try {
            $context = $this->context->build(McpConnection::findOrFail($run->mcp_connection_id), $run, Event::findOrFail($eventId), $evidence);
            $contextHash = hash('sha256', ResearchPrompt::input($context['payload']));
            $result = $this->call($run, $event->priority === 'routine', $context);
            $this->finish($run, $context, $result, $contextHash);
        } catch (Throwable $error) {
            $this->fail($run, $error, $contextHash);
        }

        return $run->fresh();
    }

    /** Releases reservations held by crashed workers, including runs whose account binding has since changed. */
    public function sweep(): int
    {
        $count = 0;
        foreach (Run::where('status', 'running')->where('lease_until', '<', now())->distinct()->pluck('mcp_connection_id') as $connectionId) {
            $count += DB::transaction(function () use ($connectionId): int {
                $connection = McpConnection::whereKey($connectionId)->lockForUpdate()->firstOrFail();
                $expired = Run::where('mcp_connection_id', $connectionId)->where('status', 'running')->where('lease_until', '<', now())->lockForUpdate()->get();
                $expired->each(fn (Run $run) => $this->abandon($connection, $run));

                return $expired->count();
            });
        }

        return $count;
    }

    private function claim(int $connectionId, int $eventId): array
    {
        $connection = McpConnection::whereKey($connectionId)->lockForUpdate()->firstOrFail();
        $event = Event::whereKey($eventId)->lockForUpdate()->firstOrFail();
        if (! $connection->bound_account_id || AccountScope::key($connection) !== $event->scope) {
            if ($event->status === 'pending') {
                $event->update(['status' => 'skipped', 'status_reason' => ResearchError::ScopeChanged->value]);
            }

            return [null, false, []];
        }
        if ($event->status !== 'pending') {
            return [$event->run_id ? Run::find($event->run_id) : null, false, []];
        }
        if ($connection->status !== 'connected') {
            // A broker reconnect is temporary: keep the work pending instead of discarding it.
            $event->update(['dispatched_at' => null, 'status_reason' => 'connection_unavailable']);

            return [null, false, []];
        }
        $scope = $event->scope;
        [$thesis, $skip] = ResearchEvents::thesisFor($connection, $scope, $event);
        if ($skip) {
            $event->update(['status' => 'skipped', 'status_reason' => $skip]);

            return [null, false, []];
        }
        $evidence = Source::where('mcp_connection_id', $connection->id)->where('scope', $scope)->where('symbol', $event->subject)->where('kind', '!=', 'quote')
            ->where(fn ($q) => $q->where('source_as_of', '>=', now()->subDays((int) config('research.source_window_days')))->orWhere('id', $event->source_id ?? 0))
            ->orderByRaw('case when id = ? then 0 else 1 end', [$event->source_id ?? 0])->orderByDesc('source_as_of')->orderByDesc('id')
            ->limit(max(1, (int) config('research.max_sources') - 1))->get(['id', 'content_hash']);
        $model = (string) config('research.model');
        // Cache on material inputs only. A changing quote alone does not justify another model call.
        $cacheKey = hash('sha256', json_encode([$event->subject, $thesis?->id, $evidence->pluck('content_hash')->sort()->values()->all(), ResearchPrompt::VERSION, $model,
            $event->kind === 'invalidation_breached' ? $event->event_key : null], JSON_THROW_ON_ERROR));
        $cached = Run::where('mcp_connection_id', $connection->id)->where('scope', $scope)->where('cache_key', $cacheKey)->where('status', 'succeeded')
            ->where('expires_at', '>', now())->latest('finished_at')->first();
        if ($cached) {
            $event->update(['status' => 'cached', 'run_id' => $cached->id, 'status_reason' => null]);

            return [$cached, false, []];
        }
        $active = Run::where('mcp_connection_id', $connection->id)->where('scope', $scope)->where('active_subject', $event->subject)->lockForUpdate()->first();
        if ($active && $active->lease_until?->isFuture()) {
            $event->update(['dispatched_at' => null, 'status_reason' => 'subject_busy']);

            return [null, false, []];
        }
        if ($active) {
            $this->abandon($connection, $active);
        }
        $run = new Run;
        $run->forceFill(['id' => (string) Str::uuid(), 'mcp_connection_id' => $connection->id, 'scope' => $scope, 'event_id' => $event->id, 'subject' => $event->subject,
            'thesis_version_id' => $thesis?->id, 'cache_key' => $cacheKey, 'active_subject' => $event->subject, 'status' => 'running',
            'provider' => (string) config('research.provider'), 'model' => $model, 'prompt_version' => ResearchPrompt::VERSION, 'prompt_hash' => ResearchPrompt::hash(),
            'budget_period' => ResearchBudget::period(), 'reserved_cost' => '0', 'actual_cost' => '0', 'attempts' => 0,
            'claim' => (string) Str::uuid(), 'lease_until' => now()->addMinutes((int) config('research.lease_minutes')), 'started_at' => now()])->save();
        $event->update(['status' => 'processing', 'run_id' => $run->id, 'status_reason' => null]);

        return [$run, true, $evidence->pluck('id')->all()];
    }

    private function call(Run $run, bool $routine, array $context): array
    {
        $error = null;
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $request = new ModelRequest($run->model, ResearchPrompt::instructions(),
                $error === null ? ResearchPrompt::input($context['payload']) : ResearchPrompt::repair($context['payload'], $error),
                ResearchSchema::schema(), ResearchSchema::NAME, (int) config('research.max_output_tokens'));
            $estimate = $this->budget->estimate($request);
            $this->reserve($run, $estimate, $routine && $attempt === 1);
            try {
                $response = $this->model->complete($request);
            } catch (ResearchFailure $failure) {
                // A request rejected before processing is unbilled; a timeout or lost response may have been billed.
                $this->settle($run, $estimate, null, null, in_array($failure->reason, [ResearchError::Authentication, ResearchError::RequestRejected, ResearchError::RateLimited, ResearchError::CredentialMissing], true));
                throw $failure;
            }
            $this->settle($run, $estimate, $response->inputTokens, $response->outputTokens, false);
            if ($response->status !== 'completed') {
                throw new ResearchFailure($response->status === 'refused' ? ResearchError::Refused : ResearchError::Incomplete);
            }
            try {
                return ResearchSchema::validate($response->text, $context['validation']);
            } catch (ResearchFailure $failure) {
                if (! $failure->reason->repairable() || $attempt === 2) {
                    throw $failure;
                }
                $error = $failure->reason;
            }
        }
        throw new LogicException('Unreachable research attempt.');
    }

    private function reserve(Run $run, Decimal $amount, bool $routine): void
    {
        DB::transaction(function () use ($run, $amount, $routine): void {
            $connection = McpConnection::whereKey($run->mcp_connection_id)->lockForUpdate()->firstOrFail();
            $owned = $this->owned($connection, $run);
            if ($this->budget->reserve($owned->budget_period, $amount, $routine)) {
                $this->incidents->record($connection, 'research_budget_reduced', 'warning', 'research');
            }
            $owned->update(['reserved_cost' => (string) Decimal::from($owned->reserved_cost)->plus($amount), 'lease_until' => now()->addMinutes((int) config('research.lease_minutes'))]);
        });
    }

    private function settle(Run $run, Decimal $reserved, ?int $inputTokens, ?int $outputTokens, bool $unbilled): void
    {
        DB::transaction(function () use ($run, $reserved, $inputTokens, $outputTokens, $unbilled): void {
            $owned = Run::whereKey($run->id)->where('claim', $run->claim)->where('status', 'running')->lockForUpdate()->first();
            if (! $owned) {
                return; // An abandoning worker already charged the full outstanding reservation.
            }
            $known = $inputTokens !== null && $outputTokens !== null;
            $actual = $unbilled ? Decimal::from('0') : ($known ? $this->budget->cost($inputTokens, $outputTokens) : $reserved);
            $basis = $unbilled ? 'provider_rejected' : ($known ? 'provider_usage' : 'reservation_unreconciled');
            $rank = ['provider_rejected' => 1, 'provider_usage' => 2, 'reservation_unreconciled' => 3];
            $this->budget->settle($owned->budget_period, $reserved, $actual);
            $owned->update(['reserved_cost' => (string) Decimal::from($owned->reserved_cost)->minus($reserved), 'actual_cost' => (string) Decimal::from($owned->actual_cost)->plus($actual),
                'input_tokens' => $inputTokens === null ? $owned->input_tokens : ($owned->input_tokens ?? 0) + $inputTokens,
                'output_tokens' => $outputTokens === null ? $owned->output_tokens : ($owned->output_tokens ?? 0) + $outputTokens,
                'cost_basis' => ($rank[$owned->cost_basis] ?? 0) >= $rank[$basis] ? $owned->cost_basis : $basis, 'attempts' => $owned->attempts + 1]);
        });
    }

    private function finish(Run $run, array $context, array $result, string $contextHash): void
    {
        DB::transaction(function () use ($run, $context, $result, $contextHash): void {
            $connection = McpConnection::whereKey($run->mcp_connection_id)->lockForUpdate()->firstOrFail();
            $owned = $this->owned($connection, $run);
            // A successful call shows the provider credential and request configuration work again.
            foreach (['research_authentication_failed', 'research_request_rejected'] as $key) {
                $this->incidents->resolve($connection, $key);
            }
            foreach ($result['material_facts'] as &$fact) {
                $fact['source_record_ids'] = array_map(fn ($id) => $context['map'][$id], $fact['source_ids']);
            }
            unset($fact);
            $owned->update(['status' => 'succeeded', 'result' => $result + ['executable' => false, 'source_map' => $context['map']], 'context_hash' => $contextHash,
                'finished_at' => now(), 'expires_at' => now()->addHours((int) config('research.cache_hours')), 'active_subject' => null, 'lease_until' => null]);
            Event::whereKey($run->event_id)->update(['status' => 'researched', 'status_reason' => null]);
        });
    }

    private function fail(Run $run, Throwable $error, ?string $contextHash): void
    {
        DB::transaction(function () use ($run, $error, $contextHash): void {
            $connection = McpConnection::whereKey($run->mcp_connection_id)->lockForUpdate()->firstOrFail();
            $owned = Run::whereKey($run->id)->where('claim', $run->claim)->where('status', 'running')->lockForUpdate()->first();
            if (! $owned) {
                return;
            }
            $reason = $error instanceof ResearchFailure ? $error->reason : null;
            $code = $reason?->value ?? 'research_failed';
            if (! $reason) {
                // Exception text can carry upstream content, so only the class name and run identifier are logged.
                Log::warning('research_run_failed', ['event' => 'research_run_failed', 'error_code' => class_basename($error), 'run_id' => $run->id]);
            }
            $budgetStop = in_array($reason, [ResearchError::BudgetExhausted, ResearchError::BudgetReduced], true);
            $outstanding = $this->release($owned);
            $owned->update(['status' => $budgetStop ? $code : 'failed', 'error_code' => $code, 'context_hash' => $contextHash, 'finished_at' => now(),
                'active_subject' => null, 'lease_until' => null] + $outstanding);
            Event::whereKey($run->event_id)->update(['status' => $budgetStop ? 'skipped' : 'failed', 'status_reason' => $code]);
            $incident = match ($reason) {
                ResearchError::Authentication, ResearchError::CredentialMissing => ['research_authentication_failed', 'critical'],
                ResearchError::RequestRejected => ['research_request_rejected', 'warning'],
                ResearchError::BudgetExhausted => ['research_budget_exhausted', 'warning'],
                ResearchError::ExecutionInstruction => ['research_output_rejected', 'warning'],
                default => null,
            };
            if ($incident && $connection->bound_account_id && AccountScope::key($connection) === $run->scope) {
                $this->incidents->record($connection, $incident[0], $incident[1], 'research');
            }
        });
    }

    /** A crashed worker's call may have been billed, so its outstanding reservation is charged in full. */
    private function abandon(McpConnection $connection, Run $active): void
    {
        $active->update(['status' => 'abandoned', 'error_code' => 'worker_lease_expired', 'active_subject' => null, 'lease_until' => null, 'finished_at' => now()] + $this->release($active));
        Event::whereKey($active->event_id)->update(['status' => 'failed', 'status_reason' => 'worker_lease_expired']);
        if ($connection->bound_account_id && AccountScope::key($connection) === $active->scope) {
            $this->incidents->record($connection, 'abandoned_research_run', 'warning', 'research');
        }
    }

    private function release(Run $run): array
    {
        $outstanding = Decimal::from($run->reserved_cost);
        if ($outstanding->compareTo(Decimal::from('0')) <= 0) {
            return [];
        }
        $this->budget->settle($run->budget_period, $outstanding, $outstanding);

        return ['reserved_cost' => '0', 'actual_cost' => (string) Decimal::from($run->actual_cost)->plus($outstanding), 'cost_basis' => 'reservation_unreconciled'];
    }

    /**
     * Fencing needs only the claim and status: reclaiming a run takes the connection lock and changes its status. An
     * unchallenged lapsed lease keeps its paid result, and broker health does not gate research publication.
     */
    private function owned(McpConnection $connection, Run $run): Run
    {
        $owned = Run::whereKey($run->id)->lockForUpdate()->firstOrFail();
        if ($owned->claim !== $run->claim || $owned->status !== 'running') {
            throw new ResearchFailure(ResearchError::LeaseLost);
        }
        if (! $connection->bound_account_id || AccountScope::key($connection) !== $run->scope) {
            throw new ResearchFailure(ResearchError::ScopeChanged);
        }

        return $owned;
    }
}
