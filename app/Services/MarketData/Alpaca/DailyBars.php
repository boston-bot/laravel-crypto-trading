<?php

declare(strict_types=1);

namespace App\Services\MarketData\Alpaca;

use App\Support\Mcp\Decimal;
use App\Support\Mcp\ExactJsonNumbers;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Read-only, split-adjusted consolidated (SIP) daily bars for completed sessions only. Fixed host and path;
 * no account, order or mutation call. Bars ending on or after the as-of New York date are never requested.
 */
final class DailyBars
{
    public const URL = 'https://data.alpaca.markets/v2/stocks/bars';

    public const CONTRACT = 'alpaca_bars_v2_1day_sip_split_v1';

    private const FIELDS = ['c', 'h', 'l', 'n', 'o', 't', 'v', 'vw'];

    private const LIMIT = 8 * 1024 * 1024;

    /** @return array<string, array> one result per requested symbol */
    /** @param int|null $lookbackDays longer windows serve retrospective replay only; eligibility uses the configured window */
    public function readMany(array $symbols, CarbonImmutable $asOf, ?int $lookbackDays = null): array
    {
        $end = $asOf->setTimezone('America/New_York')->startOfDay()->subDay();
        $start = $end->subDays(max(1, min(2400, $lookbackDays ?? (int) config('alpaca_data.history_lookback_days'))));
        $base = ['provider' => self::URL, 'contract' => self::CONTRACT, 'feed' => 'sip', 'adjustment' => 'split', 'timeframe' => '1Day',
            'start' => $start->toDateString(), 'end' => $end->toDateString(), 'retrieved_at' => null, 'complete' => false, 'error' => null,
            'bars' => [], 'requests' => 0];
        $results = [];
        foreach ($symbols as $symbol) {
            $results[$symbol] = ['symbol' => $symbol] + $base;
        }
        $error = match (true) {
            ! config('alpaca_data.enabled') || ! config('alpaca_data.reference_enabled') => 'disabled',
            ! $this->credentials() => 'credentials_missing',
            default => null,
        };
        foreach ($symbols as $symbol) {
            if (! is_string($symbol) || ! preg_match('/^[A-Z][A-Z0-9.-]{0,15}$/D', $symbol)) {
                $error ??= 'invalid_symbol';
            }
        }
        if ($error !== null || $symbols === []) {
            return array_map(fn ($result) => array_replace($result, ['error' => $error ?? 'no_symbols', 'retrieved_at' => CarbonImmutable::now()->toISOString()]), $results);
        }
        foreach (array_chunk(array_values(array_unique($symbols)), max(1, min(100, (int) config('alpaca_data.history_symbols_per_request')))) as $chunk) {
            $outcome = $this->chunk($chunk, $start->toDateString(), $end->toDateString());
            foreach ($chunk as $symbol) {
                $results[$symbol] = array_replace($results[$symbol], ['retrieved_at' => $outcome['retrieved_at'], 'requests' => $outcome['requests'],
                    'error' => $outcome['error'], 'complete' => $outcome['error'] === null, 'bars' => $outcome['error'] === null ? ($outcome['bars'][$symbol] ?? []) : []]);
            }
        }

        return $results;
    }

    private function chunk(array $chunk, string $start, string $end): array
    {
        $outcome = ['retrieved_at' => CarbonImmutable::now()->toISOString(), 'requests' => 0, 'error' => null, 'bars' => []];
        $cursor = null;
        $cursors = [];
        $last = [];
        try {
            for ($page = 0; $page < max(1, min(50, (int) config('alpaca_data.history_max_pages'))); $page++) {
                $query = ['symbols' => implode(',', $chunk), 'timeframe' => '1Day', 'start' => $start, 'end' => $end,
                    'adjustment' => 'split', 'feed' => 'sip', 'limit' => 10000, 'sort' => 'asc'];
                if ($cursor !== null) {
                    $query['page_token'] = $cursor;
                }
                $exceeded = false;
                $outcome['requests']++;
                try {
                    $response = Http::withHeaders(['APCA-API-KEY-ID' => config('alpaca_data.key'), 'APCA-API-SECRET-KEY' => config('alpaca_data.secret')])
                        ->timeout(20)->connectTimeout(5)->withOptions(['allow_redirects' => false, 'progress' => function ($total, $downloaded) use (&$exceeded): void {
                            if ($downloaded > self::LIMIT) {
                                $exceeded = true;
                                throw new \RuntimeException('Response limit.');
                            }
                        }])->get(self::URL, $query);
                } catch (\Throwable $error) {
                    if ($exceeded) {
                        return array_replace($outcome, ['error' => 'response_limit', 'bars' => []]);
                    }
                    throw $error;
                }
                $outcome['retrieved_at'] = CarbonImmutable::now()->toISOString();
                if (! $response->successful()) {
                    // Never keep provider error bodies or request details.
                    return array_replace($outcome, ['bars' => [], 'error' => match ($response->status()) {
                        401 => 'authentication_failed', 403 => 'access_denied', 429 => 'rate_limited', default => 'http_failure',
                    }]);
                }
                if (strlen($response->body()) > self::LIMIT) {
                    return array_replace($outcome, ['error' => 'response_limit', 'bars' => []]);
                }
                $body = ExactJsonNumbers::decode($response->body());
                if (! is_array($body['bars'] ?? null) || ! array_key_exists('next_page_token', $body) || array_diff(array_keys($body), ['bars', 'next_page_token', 'currency']) !== []
                    || (isset($body['currency']) && $body['currency'] !== 'USD')) {
                    throw new \InvalidArgumentException('Unreviewed response envelope.');
                }
                foreach ($body['bars'] as $symbol => $rows) {
                    if (! in_array($symbol, $chunk, true) || ! is_array($rows) || ! array_is_list($rows)) {
                        throw new \InvalidArgumentException('Unrequested symbol.');
                    }
                    foreach ($rows as $row) {
                        $bar = $this->bar($row, $start, $end);
                        // Pages are sorted by symbol then time; a repeated or earlier date is ambiguous.
                        if (isset($last[$symbol]) && $bar[0] <= $last[$symbol]) {
                            throw new \InvalidArgumentException('Unordered bars.');
                        }
                        $last[$symbol] = $bar[0];
                        $outcome['bars'][$symbol][] = $bar;
                    }
                }
                $cursor = $body['next_page_token'];
                if ($cursor === null) {
                    return $outcome;
                }
                if (! is_string($cursor) || $cursor === '' || strlen($cursor) > 4096 || isset($cursors[$cursor])) {
                    throw new \InvalidArgumentException('Invalid pagination.');
                }
                $cursors[$cursor] = true;
            }

            return array_replace($outcome, ['error' => 'page_limit', 'bars' => []]);
        } catch (ConnectionException) {
            return array_replace($outcome, ['error' => 'transport_unavailable', 'bars' => []]);
        } catch (\InvalidArgumentException|\JsonException|\TypeError|\ValueError) {
            return array_replace($outcome, ['error' => 'invalid_or_unreviewed_response', 'bars' => []]);
        } catch (\Throwable) {
            return array_replace($outcome, ['error' => 'transport_or_response_failure', 'bars' => []]);
        }
    }

    /** @return array{0: string, 1: string, 2: string, 3: string, 4: string, 5: string, 6: string, 7: string} date, o, h, l, c, v, vw, n */
    private function bar(mixed $row, string $start, string $end): array
    {
        if (! is_array($row) || array_is_list($row)) {
            throw new \InvalidArgumentException('Malformed bar.');
        }
        $keys = array_keys($row);
        sort($keys);
        if ($keys !== self::FIELDS || ! is_string($row['t']) || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,9})?Z$/D', $row['t'])) {
            throw new \InvalidArgumentException('Unreviewed bar fields.');
        }
        $local = CarbonImmutable::parse($row['t'])->setTimezone('America/New_York');
        $date = $local->toDateString();
        // A daily bar is stamped at New York midnight of its session date.
        if ($local->format('H:i:s') !== '00:00:00' || $date < $start || $date > $end) {
            throw new \InvalidArgumentException('Bar outside requested completed sessions.');
        }
        foreach (['v', 'n'] as $key) {
            if (! is_string($row[$key]) || ! preg_match('/^\d{1,18}$/D', $row[$key])) {
                throw new \InvalidArgumentException('Invalid count.');
            }
        }
        $zero = Decimal::from('0');
        $prices = [];
        foreach (['o', 'h', 'l', 'c', 'vw'] as $key) {
            $prices[$key] = Decimal::from($row[$key]);
            if ($prices[$key]->compareTo($zero) <= 0) {
                throw new \InvalidArgumentException('Non-positive price.');
            }
        }
        foreach (['o', 'c'] as $key) {
            if ($prices['l']->compareTo($prices[$key]) > 0 || $prices['h']->compareTo($prices[$key]) < 0) {
                throw new \InvalidArgumentException('Inconsistent bar range.');
            }
        }

        return [$date, $row['o'], $row['h'], $row['l'], $row['c'], $row['v'], $row['vw'], $row['n']];
    }

    private function credentials(): bool
    {
        return is_string(config('alpaca_data.key')) && config('alpaca_data.key') !== '' && is_string(config('alpaca_data.secret')) && config('alpaca_data.secret') !== '';
    }
}
