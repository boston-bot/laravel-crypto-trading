<?php

namespace App\Http\Controllers;

use App\Models\McpConnection;
use App\Models\Operations\NotificationDelivery;
use App\Services\Operations\Notifications;
use App\Services\Operations\NtfyNotifier;
use App\Support\Mcp\DatabaseReadiness;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * Browser equivalents of safe operator commands. None calls the broker or an AI model, and none changes policy, mode,
 * schedules or credentials. Long-running or privileged commands (backup, restore drill, import) stay terminal-only.
 */
class OperatorActionController extends Controller
{
    /** Same as `php artisan mcp:health --no-ping`: records/resolves health incidents; the external heartbeat is not pinged. */
    public function health(Request $request)
    {
        return $this->run($request, 'health', 'Health checks', function (): array {
            $exit = Artisan::call('mcp:health', ['--no-ping' => true]);
            $result = json_decode(trim(Artisan::output()), true);
            if (! is_array($result) || ! isset($result['checks'])) {
                return ['bad', 'Health checks did not report a result.', []];
            }
            $problems = array_filter($result['checks'], fn ($status) => ! in_array($status, ['ok', 'disabled'], true));
            $lines = array_map(fn ($name, $status) => $name.': '.$status, array_keys($problems), $problems);

            return [$exit === 0 ? ($problems ? 'warn' : 'ok') : 'bad',
                $exit === 0 ? 'No critical checks. Health incidents were updated; the heartbeat was not pinged.' : 'Critical checks found. Health incidents were updated; the heartbeat was not pinged.',
                $lines ?: ['All checks ok.']];
        });
    }

    /** Same as `php artisan mcp:notify --test` without escalation: queues a test intent and dispatches pending alerts. */
    public function testAlert(Request $request, Notifications $notifications, NtfyNotifier $ntfy)
    {
        return $this->run($request, 'test_alert', 'Test alert', function () use ($notifications, $ntfy): array {
            if (! $ntfy->configured()) {
                return ['warn', 'No alert destination is configured; nothing was queued.', []];
            }
            $started = now();
            $notifications->queueTest();
            $counts = $notifications->dispatch() ?? [];
            $test = NotificationDelivery::where('kind', 'test')->where('attempted_at', '>=', $started)->latest('id')->first();
            $lines = ['Dispatch: '.implode(', ', array_map(fn ($k, $v) => $v.' '.$k, array_keys($counts), $counts)).'.'];

            return $test?->status === 'delivered'
                ? ['ok', 'ntfy acknowledged the test alert. Confirm it arrived on your phone; provider acceptance alone does not prove phone delivery.', $lines]
                : ['warn', 'The test alert was queued but not acknowledged'.($test ? ' ('.$test->status.')' : '').'. The scheduler retries pending alerts.', $lines];
        });
    }

    /** Same as `php artisan mcp:summary --kind=… --account=<own>`: deterministic summary of the previous closed period. */
    public function summary(Request $request)
    {
        $kind = $request->validate(['kind' => ['required', Rule::in(['daily', 'weekly'])]])['kind'];
        $connection = McpConnection::where('user_id', $request->user()->id)->whereNotNull('bound_account_id')->first();

        return $this->run($request, 'summary_'.$kind, ucfirst($kind).' summary', function () use ($connection, $kind): array {
            if (! $connection) {
                return ['warn', 'Bind an account before generating summaries.', []];
            }
            $exit = Artisan::call('mcp:summary', ['--kind' => $kind, '--account' => (string) $connection->id]);
            $result = json_decode(trim(Artisan::output()), true);

            return $exit === 0 && is_array($result)
                ? ['ok', ucfirst($kind).' summary for '.$result['period'].' is recorded. Repeating it returns the same immutable record.', []]
                : ['warn', 'No summary recorded. Only closed periods can be summarized.', []];
        });
    }

    /** Same as `php artisan mcp:doctor`: read-only database identity and schema placement; no credentials are shown. */
    public function database(Request $request, DatabaseReadiness $readiness)
    {
        return $this->run($request, 'database_check', 'Database check', function () use ($readiness): array {
            try {
                $state = $readiness->inspect();
            } catch (Throwable) {
                return ['bad', 'Database unavailable or application schema invalid. Check private database configuration.', []];
            }

            return [$state['migrations_present'] ? 'ok' : 'bad', $state['migrations_present'] ? 'Database identity and schema verified.' : 'Migrations table missing from the application schema.',
                ['Database: '.$state['database'], 'Schema: '.$state['schema'], 'Connected role: '.$state['role'], 'Schema owner: '.$state['owner']]];
        });
    }

    /** @param  Closure(): array{string, string, list<string>}  $action */
    private function run(Request $request, string $name, string $label, Closure $action)
    {
        $lock = Cache::lock('operator-action:'.$name, 120);
        if (! $lock->get()) {
            return $this->result($label, 'warn', 'Already running; try again shortly.', []);
        }
        try {
            [$tone, $message, $lines] = $action();
        } catch (Throwable $error) {
            [$tone, $message, $lines] = ['bad', 'The action failed ('.class_basename($error).'). Check the application logs.', []];
        } finally {
            $lock->release();
        }
        // Approver/actor identity comes from the authenticated session, never from request input.
        Log::info('operator_action', ['event' => 'operator_action', 'action' => $name, 'actor_id' => $request->user()->id, 'outcome' => $tone]);

        return $this->result($label, $tone, $message, $lines);
    }

    private function result(string $label, string $tone, string $message, array $lines)
    {
        return redirect()->to(route('mcp.dashboard', ['page' => 'health']).'#operator-actions')
            ->with('operator_result', ['label' => $label, 'tone' => $tone, 'message' => $message, 'lines' => $lines, 'at' => now()->toIso8601String()]);
    }
}
