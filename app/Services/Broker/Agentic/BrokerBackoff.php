<?php

namespace App\Services\Broker\Agentic;

use Illuminate\Support\Facades\Cache;

/**
 * Per-connection pause after the broker rate-limits a read. Every caller sharing the cache store waits it out,
 * so later reads in a batch or another worker do not keep hitting the broker.
 */
final class BrokerBackoff
{
    public function remaining(int $connectionId): int
    {
        $until = Cache::get($this->key($connectionId));

        return is_int($until) ? max(0, $until - now()->getTimestamp()) : 0;
    }

    public function record(int $connectionId, ?int $retryAfter): int
    {
        $seconds = min(3600, max((int) config('robinhood_mcp.rate_limit_min_backoff_seconds'), $retryAfter ?? 0));
        $until = now()->getTimestamp() + $seconds;
        $current = Cache::get($this->key($connectionId));
        if (! is_int($current) || $current < $until) {
            Cache::put($this->key($connectionId), $until, $seconds);
        }

        return $this->remaining($connectionId);
    }

    private function key(int $connectionId): string
    {
        return 'mcp-broker-backoff:'.$connectionId;
    }
}
