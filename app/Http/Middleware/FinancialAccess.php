<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class FinancialAccess
{
    public function handle(Request $request, Closure $next, string $ability)
    {
        Gate::authorize($ability);

        return $next($request);
    }
}
