<?php

namespace App\Services\Broker\Agentic;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

final class TrustedHttp
{
    public const RESOURCE = 'https://agent.robinhood.com/mcp/trading';

    public static function validateUrl(mixed $url): string
    {
        if (! is_string($url)) {
            throw new ReadFailure(ReadError::OAuth);
        }
        $parts = parse_url($url);
        if (! $parts || ($parts['scheme'] ?? '') !== 'https' || ! in_array($parts['host'] ?? '', ['agent.robinhood.com', 'api.robinhood.com', 'robinhood.com'], true)
            || isset($parts['user']) || isset($parts['fragment']) || isset($parts['port'])) {
            throw new ReadFailure(ReadError::OAuth);
        }

        return $url;
    }

    public function send(string $method, string $url, array $options = []): Response
    {
        self::validateUrl($url);
        try {
            $response = Http::timeout(10)->connectTimeout(5)->withOptions(['allow_redirects' => false,
                'progress' => function ($total, $downloaded): void {
                    if ($downloaded > 8 * 1024 * 1024) {
                        throw new ReadFailure(ReadError::Malformed);
                    }
                }])->send($method, $url, $options);
        } catch (ConnectionException) {
            throw new ReadFailure(ReadError::Timeout);
        }
        if (strlen($response->body()) > 8 * 1024 * 1024) {
            throw new ReadFailure(ReadError::Malformed);
        }

        return $response;
    }

    public static function checkStatus(Response $response): void
    {
        if ($response->successful()) {
            return;
        }
        throw new ReadFailure(match ($response->status()) {
            401 => ReadError::Authentication, 403 => ReadError::Forbidden, 429 => ReadError::RateLimited,
            default => ReadError::Transport,
        }, $response->status() === 429 ? min(3600, max(1, (int) $response->header('Retry-After'))) : null);
    }
}
