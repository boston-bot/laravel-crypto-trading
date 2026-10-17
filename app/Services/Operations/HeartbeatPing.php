<?php

namespace App\Services\Operations;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/** Dead man's switch: the external service alerts when pings stop or report failure. Bodies name failing checks only. */
final class HeartbeatPing
{
    public function configured(): bool
    {
        return self::url() !== null;
    }

    public function ping(array $failing): string
    {
        if (! ($url = self::url())) {
            return 'unconfigured';
        }
        try {
            $response = Http::timeout(10)->connectTimeout(5)->withOptions(['allow_redirects' => false])
                ->withBody($failing === [] ? 'ok' : implode("\n", $failing), 'text/plain')->post($url.($failing === [] ? '' : '/fail'));
        } catch (ConnectionException) {
            return 'unreachable';
        }

        return $response->successful() ? 'acknowledged' : 'http_'.$response->status();
    }

    private static function url(): ?string
    {
        $url = config('operations.healthchecks_url');
        $parts = is_string($url) ? parse_url($url) : false;
        if (! $parts || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || empty($parts['path']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query'])) {
            return null;
        }

        return rtrim($url, '/');
    }
}
