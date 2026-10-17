<?php

namespace App\Http\Controllers;

use App\Models\McpConnection;
use App\Services\Broker\Agentic\AccountBinding;
use App\Services\Broker\Agentic\OAuthService;
use App\Services\Broker\Agentic\Operation;
use App\Services\Broker\Agentic\ReadError;
use App\Services\Broker\Agentic\ReadFailure;
use App\Services\Broker\Agentic\RobinhoodGateway;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;

class McpConnectionController extends Controller
{
    public function show(Request $request)
    {
        return response()->view('mcp.connection', ['connection' => McpConnection::where('user_id', $request->user()->id)->first(),
            'enabled' => config('robinhood_mcp.enabled'), 'operations' => array_keys(config('robinhood_mcp.operations'))])->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function connect(Request $request, OAuthService $oauth)
    {
        try {
            return redirect()->away($oauth->begin($request->user()->id, $request->session()->getId()));
        } catch (ReadFailure $failure) {
            return redirect()->route('mcp.connection')->with('mcp_error', $failure->reason->value);
        }
    }

    public function callback(Request $request, OAuthService $oauth)
    {
        try {
            $oauth->complete($request->user()->id, $request->session()->getId(), $request->query());

            return redirect()->route('mcp.connection')->with('mcp_status', 'Authorization saved. Discover capabilities, then review the read-only mapping before binding an account.');
        } catch (ReadFailure $failure) {
            return redirect()->route('mcp.connection')->with('mcp_error', $failure->reason->value);
        }
    }

    public function renew(Request $request, OAuthService $oauth)
    {
        try {
            $oauth->renew($this->connection($request));

            return redirect()->route('mcp.connection')->with('mcp_status', 'OAuth credentials renewed. Account binding and trading settings were not changed.');
        } catch (ReadFailure $failure) {
            return redirect()->route('mcp.connection')->with('mcp_error', $failure->reason->value);
        }
    }

    public function discover(Request $request, RobinhoodGateway $gateway)
    {
        $connection = $this->connection($request);
        try {
            $gateway->discover($connection);

            return redirect()->route('mcp.connection')->with('mcp_status', 'Capability catalog saved. No brokerage tools were called.');
        } catch (ReadFailure $failure) {
            return redirect()->route('mcp.connection')->with('mcp_error', $failure->reason->value);
        }
    }

    public function accounts(Request $request, RobinhoodGateway $gateway)
    {
        try {
            $result = $gateway->read($this->connection($request), Operation::Accounts);
            if (! $result->complete) {
                throw new ReadFailure(ReadError::Incomplete);
            }
            $choices = array_map(fn ($record) => ['selection' => Crypt::encryptString($record->fields['id']),
                'label' => '…'.substr($record->fields['id'], -4), 'kind' => $record->fields['account_kind']], $result->records);

            return response()->view('mcp.accounts', ['choices' => $choices])->header('Cache-Control', 'no-store');
        } catch (ReadFailure $failure) {
            return redirect()->route('mcp.connection')->with('mcp_error', $failure->reason->value);
        }
    }

    public function bind(Request $request, AccountBinding $binding)
    {
        $input = $request->validate(['selection' => ['required', 'string', 'max:4096'], 'role' => ['required', 'in:agentic_monitoring,personal_advisory']]);
        try {
            $accountId = Crypt::decryptString($input['selection']);
        } catch (DecryptException) {
            abort(422, 'Invalid account selection.');
        }
        try {
            $binding->bind($this->connection($request), $accountId, $input['role'], $request->user()->id);

            return redirect()->route('mcp.connection')->with('mcp_status', 'Account bound for read-only use.');
        } catch (ReadFailure $failure) {
            return redirect()->route('mcp.connection')->with('mcp_error', $failure->reason->value);
        }
    }

    public function read(Request $request, string $operation, RobinhoodGateway $gateway)
    {
        $operation = Operation::tryFrom($operation);
        abort_unless($operation && $operation !== Operation::Accounts, 404);
        $filters = $request->validate(['symbol' => ['sometimes', 'string', 'max:64'], 'instrument_id' => ['sometimes', 'string', 'max:128'], 'order_id' => ['sometimes', 'string', 'max:128']]);
        try {
            $result = $gateway->read($this->connection($request), $operation, $filters);
            $payload = $result->jsonSerialize();
            $payload['records'] = array_map(function ($record): array {
                $row = $record->jsonSerialize();
                if (isset($row['data']['account_id'])) {
                    $row['data']['account_id'] = '…'.substr($row['data']['account_id'], -4);
                }

                return $row;
            }, $result->records);

            return response()->json($payload)->header('Cache-Control', 'no-store');
        } catch (ReadFailure $failure) {
            return response()->json(['error' => $failure->reason->value], 503)->header('Cache-Control', 'no-store');
        }
    }

    private function connection(Request $request): McpConnection
    {
        return McpConnection::where('user_id', $request->user()->id)->firstOrFail();
    }
}
