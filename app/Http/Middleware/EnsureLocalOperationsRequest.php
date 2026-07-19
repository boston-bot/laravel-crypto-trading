<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureLocalOperationsRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! (bool) config('operations.local_only', true)) {
            return $next($request);
        }

        $remote = (string) $request->server('REMOTE_ADDR', '');
        if (! in_array($remote, ['127.0.0.1', '::1'], true)) {
            abort(403, 'The trading operations console is available only from this machine.');
        }

        return $next($request);
    }
}
