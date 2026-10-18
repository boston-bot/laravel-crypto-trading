<?php

namespace App\Services\PaperTrading;

use App\Jobs\PaperDecisionJob;
use App\Models\McpConnection;
use App\Models\PaperTrading\DecisionRun;
use App\Models\PaperTrading\PolicyApplication;
use App\Models\PaperTrading\Portfolio;
use App\Models\User;
use App\Services\Monitoring\AccountScope;
use App\Services\Monitoring\MarketCalendar;
use App\Services\Research\ResearchReadiness;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class DecisionScheduler
{
    public function __construct(private MarketCalendar $calendar, private DecisionRunner $runner) {}

    public static function readiness(): ?string
    {
        return ! config('paper.decisions_enabled') ? 'paper_decisions_disabled' : ResearchReadiness::reason()?->value;
    }

    public function window(PolicyApplication $application, CarbonImmutable $now): ?array
    {
        $session = $this->calendar->at($now);
        if ($session['status'] !== 'regular') {
            return null;
        }
        $open = CarbonImmutable::parse($session['opens_at']);
        $close = CarbonImmutable::parse($session['closes_at']);
        $seconds = (int) $application->snapshot['rules']['decision_minutes'] * 60;
        $start = $open->addSeconds(intdiv($now->getTimestamp() - $open->getTimestamp(), $seconds) * $seconds);

        $next = $start->addSeconds($seconds);

        return ['start' => $start, 'end' => $next->min($close), 'next_start' => $next->lt($close) ? $next : null, 'key' => $start->utc()->format('YmdHi')];
    }

    /** Dispatch only the current window; never backfill missed decisions. */
    public function tick(bool $foreground = false): int
    {
        if (! config('paper.decisions_enabled')) {
            return 0;
        }
        $this->runner->sweep();
        $operators = User::whereIn('email', config('mcp.operator_emails'))->pluck('id');
        $count = 0;
        foreach (McpConnection::whereIn('user_id', $operators)->whereNotNull('bound_account_id')->where('status', 'connected')->get() as $connection) {
            $id = DB::transaction(function () use ($connection, $foreground) {
                $current = McpConnection::whereKey($connection->id)->lockForUpdate()->firstOrFail();
                if (! $current->bound_account_id || $current->status !== 'connected') {
                    return null;
                }
                $portfolio = Portfolio::where('mcp_connection_id', $current->id)->where('scope', AccountScope::key($current))->first();
                if (! $portfolio) {
                    return null;
                }
                $application = PolicyApplication::where('paper_portfolio_id', $portfolio->id)->where('revision', $portfolio->policy_revision)->firstOrFail();
                $window = $this->window($application, CarbonImmutable::now());
                if (! $window) {
                    return null;
                }
                $key = 'scheduled:'.$application->id.':'.$window['key'];
                $run = DecisionRun::where('paper_portfolio_id', $portfolio->id)->where('dedup_key', $key)->first();
                if (! $run) {
                    $reason = self::readiness();
                    $run = new DecisionRun;
                    $run->forceFill(['id' => (string) Str::uuid(), 'mcp_connection_id' => $current->id, 'scope' => AccountScope::key($current),
                        'paper_portfolio_id' => $portfolio->id, 'policy_application_id' => $application->id, 'ledger_version' => $portfolio->ledger_version,
                        'auth_generation' => $current->auth_generation, 'dedup_key' => $key, 'status' => $reason ? 'unavailable' : 'queued', 'reason' => $reason,
                        'scheduled_at' => $window['start'], 'expires_at' => $window['end'], 'finished_at' => $reason ? now() : null, 'created_at' => now()])->save();
                }
                if ($run->status !== 'queued' || (! $foreground && $run->dispatched_at?->gt(now()->subMinute()))) {
                    return null;
                }
                if (! $foreground) {
                    PaperDecisionJob::dispatch($run->id, $run->scope);
                    $run->update(['dispatched_at' => now()]);
                }

                return $run->id;
            });
            if ($id) {
                if ($foreground) {
                    $this->runner->process($id);
                }
                $count++;
            }
        }

        return $count;
    }
}
