<?php

namespace App\Http\Controllers;

use App\Services\Operations\NtfyNotifier;

/** Explicit operator reveal: topic names are shared secrets and stay off the ordinary health page. */
class OperationsSetupController extends Controller
{
    public function phone(NtfyNotifier $notifier)
    {
        abort_unless($notifier->configured(), 409, 'Configure an ntfy topic in the private .env first.');

        return response()->view('mcp.phone-setup', [
            'server' => rtrim(config('operations.ntfy.url'), '/'),
            'topic' => config('operations.ntfy.topic'),
            'authenticatedServer' => filled(config('operations.ntfy.token')),
        ]);
    }
}
