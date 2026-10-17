<?php

namespace App\Services\Operations;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

final class NtfyNotifier
{
    public function configured(): bool
    {
        return self::url() !== null && preg_match('/^[A-Za-z0-9_-]{16,64}$/D', (string) config('operations.ntfy.topic')) === 1;
    }

    /** @return string the provider's message id; only an acknowledged publish counts as delivered */
    public function send(string $title, string $message, int $priority, array $tags = []): string
    {
        if (! $this->configured()) {
            throw new NotificationFailure('ntfy_unconfigured');
        }
        $click = config('operations.dashboard_url');
        try {
            $request = Http::timeout(10)->connectTimeout(5)->acceptJson()->withOptions(['allow_redirects' => false]);
            if (filled(config('operations.ntfy.token'))) {
                $request = $request->withToken((string) config('operations.ntfy.token'));
            }
            $response = $request->post(self::url(), array_filter(['topic' => config('operations.ntfy.topic'), 'title' => $title, 'message' => $message,
                'priority' => $priority, 'tags' => $tags, 'click' => is_string($click) && str_starts_with($click, 'https://') ? $click : null]));
        } catch (ConnectionException) {
            throw new NotificationFailure('ntfy_unreachable');
        }
        $id = $response->json('id');
        if (! $response->successful() || ! is_string($id) || $id === '') {
            throw new NotificationFailure($response->successful() ? 'ntfy_unacknowledged' : 'ntfy_http_'.$response->status());
        }

        return $id;
    }

    private static function url(): ?string
    {
        $url = config('operations.ntfy.url');
        $parts = is_string($url) ? parse_url($url) : false;
        if (! $parts || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            return null;
        }

        return rtrim($url, '/').'/';
    }
}
