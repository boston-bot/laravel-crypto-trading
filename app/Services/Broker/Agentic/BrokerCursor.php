<?php

namespace App\Services\Broker\Agentic;

final class BrokerCursor
{
    public static function normalize(string $next, ?string $accountId): string
    {
        if (! str_contains($next, '://')) {
            return $next;
        }
        // Parse broker pagination links as data. Never fetch a broker-supplied URL.
        $parts = parse_url($next);
        if (! $parts || ($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['port']) || isset($parts['fragment'])
            || ! in_array($parts['host'] ?? '', ['api.robinhood.com', 'agent.robinhood.com'], true)) {
            throw new ReadFailure(ReadError::Incomplete);
        }
        parse_str($parts['query'] ?? '', $query);
        if (isset($query['account_number']) && $query['account_number'] !== $accountId) {
            throw new ReadFailure(ReadError::AccountMismatch);
        }
        if (! is_string($query['cursor'] ?? null) || $query['cursor'] === '') {
            throw new ReadFailure(ReadError::Incomplete);
        }

        return $query['cursor'];
    }
}
