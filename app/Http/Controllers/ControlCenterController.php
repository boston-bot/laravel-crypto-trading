<?php

namespace App\Http\Controllers;

use App\Models\McpConnection;
use App\Models\PaperTrading\MandateVersion;
use App\Services\Monitoring\AccountScope;
use App\Services\PaperTrading\PaperMandates;
use App\Services\PaperTrading\PaperPolicies;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use JsonException;

class ControlCenterController extends Controller
{
    public function apply(Request $request, PaperPolicies $policies, int $version)
    {
        $data = $request->validate(['base_version' => ['required', 'integer', 'min:1'], 'base_policy_revision' => ['required', 'integer', 'min:0'],
            'request_id' => ['required', 'uuid'], 'confirm_paper' => ['accepted']]);
        $policies->apply($this->connection($request), $request->user()->id, $version, (int) $data['base_version'], (int) $data['base_policy_revision'], $data['request_id']);

        return redirect()->route('mcp.dashboard', ['page' => 'control'])->with('monitor_status', 'Policy applied to the separate virtual portfolio. No scheduler, AI request or brokerage operation was started.');
    }

    public function store(Request $request, PaperMandates $mandates)
    {
        $data = $request->validate(['base_version' => ['required', 'integer', 'min:0'], 'request_id' => ['required', 'uuid'],
            'reason' => ['required', 'string', 'max:500']]);
        $mandates->append($this->connection($request), $request->user()->id, (int) $data['base_version'], $data['request_id'],
            $request->except(['_token', 'base_version', 'request_id', 'reason', 'account_scope']), $data['reason']);

        return redirect()->route('mcp.dashboard', ['page' => 'control'])->with('monitor_status', 'Paper draft saved. It is not active or sent to an AI model.');
    }

    public function restore(Request $request, PaperMandates $mandates, int $version)
    {
        $data = $request->validate(['base_version' => ['required', 'integer', 'min:0'], 'request_id' => ['required', 'uuid']]);
        $mandates->append($this->connection($request), $request->user()->id, (int) $data['base_version'], $data['request_id'], [], 'Restored an earlier paper draft.', $version);

        return redirect()->route('mcp.dashboard', ['page' => 'control'])->with('monitor_status', 'Earlier settings copied into a new paper draft. History is retained; nothing was activated.');
    }

    /** Pasted proposals are untrusted input. This previews validated fields without persisting or activating them. */
    public function proposal(Request $request, PaperMandates $mandates)
    {
        $data = $request->validate(['proposal_json' => ['required', 'string', 'max:16000'], 'base_version' => ['required', 'integer', 'min:0']]);
        $connection = $this->connection($request);
        $latest = MandateVersion::where('mcp_connection_id', $connection->id)->where('scope', AccountScope::key($connection))->latest('version')->first();
        abort_unless(($latest?->version ?? 0) === (int) $data['base_version'], 409, 'A newer draft exists. Reload before reviewing a proposal.');
        try {
            $patch = json_decode($data['proposal_json'], true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw ValidationException::withMessages(['proposal_json' => 'Paste a JSON object containing only paper planning fields.']);
        }
        if (! is_array($patch) || ! str_starts_with(ltrim($data['proposal_json']), '{')) {
            throw ValidationException::withMessages(['proposal_json' => 'A JSON object is required.']);
        }
        $payload = $mandates->validate(array_replace($latest?->payload ?? [], $patch));

        return redirect()->route('mcp.dashboard', ['page' => 'control'])->withInput($payload + ['base_version' => $data['base_version'], 'account_scope' => AccountScope::key($connection), 'reason' => 'Reviewed a pasted settings proposal.'])
            ->with('monitor_status', 'Proposal loaded into the editor for review. Compare it with the saved draft below, then save if appropriate. Nothing has been saved or sent.');
    }

    private function connection(Request $request): McpConnection
    {
        $data = $request->validate(['account_scope' => ['required', 'string', 'size:64', 'regex:/^[a-f0-9]+$/D']]);
        $connection = McpConnection::where('user_id', $request->user()->id)->whereNotNull('bound_account_id')->firstOrFail();
        abort_unless(hash_equals(AccountScope::key($connection), $data['account_scope']), 409, 'Account binding changed. Reload the control center.');

        return $connection;
    }
}
