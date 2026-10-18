<?php

namespace App\Http\Controllers;

use App\Models\McpConnection;
use App\Services\Monitoring\AccountScope;
use App\Services\PaperTrading\ExecutionPolicies;
use App\Services\PaperTrading\PaperMarketInputs;
use App\Services\PaperTrading\PaperOrderHandoff;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PaperExecutionController extends Controller
{
    public function store(Request $request, ExecutionPolicies $policies)
    {
        $data = $request->validate(['base_version' => ['required', 'integer', 'min:0'], 'request_id' => ['required', 'uuid'],
            'reason' => ['required', 'string', 'max:500']]);
        $payload = $this->payload($request);
        $policies->save($this->connection($request), $request->user()->id, (int) $data['base_version'], $data['request_id'], $payload, $data['reason']);

        return $this->back('Execution draft saved. No settings applied, API call or order started.');
    }

    public function apply(Request $request, ExecutionPolicies $policies, int $version)
    {
        $data = $request->validate(['base_version' => ['required', 'integer', 'min:1'], 'request_id' => ['required', 'uuid'], 'confirm_paper' => ['accepted']]);
        $policies->apply($this->connection($request), $request->user()->id, (int) $data['base_version'], $data['request_id'], $version);

        return $this->back('Paper execution rules applied. Collection, research and handoff enablement are separate; nothing was dispatched.');
    }

    public function collect(Request $request, PaperMarketInputs $inputs)
    {
        $data = $request->validate(['symbol' => ['required', 'string', 'regex:/^[A-Z][A-Z0-9.-]{0,15}$/D'], 'request_id' => ['required', 'uuid']]);
        $input = $inputs->collect($this->connection($request), $request->user()->id, $data['symbol'], $data['request_id']);

        return $this->back('Read-only market input recorded: '.$input->status.'. Unsupported execution fields remain unknown.');
    }

    public function handoff(Request $request, PaperOrderHandoff $handoff, string $run)
    {
        $request->validate(['confirm_paper' => ['accepted']]);
        $outcomes = $handoff->process($this->connection($request), $request->user()->id, $run);

        return $this->back('Paper handoff outcomes recorded: '.$outcomes->count().'. Review the evidence below; no brokerage operation was performed.');
    }

    private function connection(Request $request): McpConnection
    {
        $data = $request->validate(['account_scope' => ['required', 'string', 'size:64', 'regex:/^[a-f0-9]+$/D']]);
        $connection = McpConnection::where('user_id', $request->user()->id)->whereNotNull('bound_account_id')->firstOrFail();
        abort_unless(hash_equals(AccountScope::key($connection), $data['account_scope']), 409, 'Account binding changed.');

        return $connection;
    }

    private function payload(Request $request): array
    {
        if ($request->has('execution_json')) {
            $data = $request->validate(['execution_json' => ['required', 'string', 'max:16000'], 'execution' => ['prohibited']]);
            try {
                $payload = json_decode($data['execution_json'], true, 8, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw ValidationException::withMessages(['execution_json' => 'Use a JSON object containing paper execution settings.']);
            }
            if (! is_array($payload) || ! str_starts_with(ltrim($data['execution_json']), '{')) {
                throw ValidationException::withMessages(['execution_json' => 'A JSON object is required.']);
            }

            return $payload;
        }
        $data = $request->validate(['execution' => ['required', 'array'], 'execution_symbols' => ['nullable', 'string', 'max:2000']]);
        $payload = $data['execution'];
        abort_if(array_key_exists('allowed_symbols', $payload), 422, 'Use the symbol list field.');
        $symbols = trim($data['execution_symbols'] ?? '');
        $payload['allowed_symbols'] = $symbols === '' ? null : array_map('trim', preg_split('/[,\r\n]+/', $symbols));
        foreach (['allow_entries', 'allow_exits', 'allow_ai_sizing', 'allow_fractional', 'allow_fractional_limit'] as $field) {
            $value = $payload[$field] ?? null;
            if (! in_array($value, [null, '', '0', '1'], true)) {
                throw ValidationException::withMessages(['execution.'.$field => 'Choose allowed, disallowed or unspecified.']);
            }
            $payload[$field] = $value === null || $value === '' ? null : $value === '1';
        }

        return $payload;
    }

    private function back(string $message)
    {
        return redirect()->route('mcp.dashboard', ['page' => 'control'])->with('monitor_status', $message);
    }
}
