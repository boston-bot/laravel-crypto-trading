<?php

namespace App\Http\Controllers;

use App\Models\McpConnection;
use App\Services\Execution\AutomaticTrading;
use App\Services\Execution\BrokerOutcomes;
use App\Services\Execution\ExecutionControls;
use App\Services\Execution\ExecutionJournal;
use App\Services\Execution\LivePolicies;
use App\Services\Monitoring\AccountScope;
use Illuminate\Http\Request;

/**
 * Owner actions for the live boundary. None of them enables brokerage writes, starts a dispatcher or sends an order;
 * every service rechecks operator identity, account binding and authorization under its own locks.
 */
class LiveExecutionController extends Controller
{
    public function startAutomatic(Request $request, AutomaticTrading $automatic)
    {
        $data = $request->validate(['request_id' => ['required', 'uuid'], 'base_version' => ['required', 'integer', 'min:0'],
            'confirm_automatic' => ['accepted'], 'confirm_exclusive_executor' => ['accepted']]);
        $automatic->set($this->connection($request), $request->user()->id, $data['request_id'], true, (int) $data['base_version'], true);

        return $this->done('Automatic trading enabled under your current live policy. The next scheduled cycle may submit real orders.');
    }

    public function stopAutomatic(Request $request, AutomaticTrading $automatic)
    {
        $data = $request->validate(['request_id' => ['required', 'uuid']]);
        $automatic->set($this->connection($request), $request->user()->id, $data['request_id'], false, 0);

        return $this->done('All new order submissions stopped. Orders already being submitted or accepted by Robinhood may still fill; review them in Robinhood.');
    }

    public function pauseBuys(Request $request, ExecutionControls $controls)
    {
        $data = $request->validate(['request_id' => ['required', 'uuid'], 'paused' => ['required', 'boolean']]);
        $controls->pauseBuys($this->connection($request), $request->user()->id, $data['request_id'], $request->boolean('paused'));

        return $this->done($request->boolean('paused') ? 'New buys paused. Automatic exits remain available while trading is enabled.' : 'New buys resumed under the current live policy.');
    }

    public function policy(Request $request, LivePolicies $policies)
    {
        $data = $request->validate(['base_version' => ['required', 'integer', 'min:0'], 'request_id' => ['required', 'uuid'],
            'live' => ['required', 'array'], 'confirm_live_policy' => ['accepted']]);
        $input = array_intersect_key($data['live'], array_flip(LivePolicies::FIELDS));
        $input['allow_fractional'] = $request->boolean('live.allow_fractional');
        // A required choice, not a checkbox: the owner states which Robinhood approval mode they expect.
        abort_unless(in_array($data['live']['require_broker_approval'] ?? null, ['0', '1'], true), 422, 'Choose the expected broker approval mode.');
        $input['require_broker_approval'] = $data['live']['require_broker_approval'] === '1';
        $policies->apply($this->connection($request), $request->user()->id, (int) $data['base_version'], $data['request_id'], $input + $data['live']);

        return $this->done('Live policy version applied. Brokerage writes remain '.(config('mcp.brokerage_writes_enabled') ? 'enabled by configuration' : 'disabled').'; staged intents with the previous fingerprint can no longer be claimed.');
    }

    public function controls(Request $request, ExecutionControls $controls)
    {
        $data = $request->validate(['request_id' => ['required', 'uuid'], 'reason' => ['required', 'string', 'max:200'],
            'buy_paused' => ['required', 'boolean'], 'emergency_disabled' => ['required', 'boolean']]);
        $controls->set($this->connection($request), $request->user()->id, $data['request_id'], $request->boolean('buy_paused'), $request->boolean('emergency_disabled'), $data['reason']);

        return $this->done('Live controls recorded. Emergency disable denies every claim and withdraws a claimed order before it is sent.');
    }

    public function link(Request $request, ExecutionJournal $journal, int $intent)
    {
        $data = $request->validate(['request_id' => ['required', 'uuid'], 'external_order_id' => ['required', 'integer', 'min:1'],
            'note' => ['required', 'string', 'max:500'], 'confirm_link' => ['accepted']]);
        $journal->link($this->connection($request), $request->user()->id, $intent, (int) $data['external_order_id'], $data['request_id'], $data['note']);

        return $this->done('Unknown outcome linked to the broker order. Its fills are recorded from the next order-history read.');
    }

    public function absent(Request $request, ExecutionJournal $journal, int $intent)
    {
        $request->validate(['confirm_absent' => ['accepted']]);
        $journal->absent($this->connection($request), $request->user()->id, $intent);

        return $this->done('No broker order recorded for this intent. Nothing was retried; a new order needs a new signal.');
    }

    public function review(Request $request, BrokerOutcomes $outcomes)
    {
        $data = $request->validate(['incident_key' => ['required', 'in:'.BrokerOutcomes::MISMATCH.','.BrokerOutcomes::MISSING], 'confirm_reconciliation' => ['accepted']]);
        $outcomes->review($this->connection($request), $request->user()->id, $data['incident_key']);

        return $this->done('Reconciliation incident reviewed. It reopens and blocks again if the next order-history read still contradicts the journal.');
    }

    private function done(string $message)
    {
        return redirect()->route('mcp.dashboard', ['page' => 'control'])->with('monitor_status', $message);
    }

    private function connection(Request $request): McpConnection
    {
        $data = $request->validate(['account_scope' => ['required', 'string', 'size:64', 'regex:/^[a-f0-9]+$/D']]);
        $connection = McpConnection::where('user_id', $request->user()->id)->whereNotNull('bound_account_id')->firstOrFail();
        abort_unless(hash_equals(AccountScope::key($connection), $data['account_scope']), 409, 'Account binding changed. Reload the control center.');

        return $connection;
    }
}
