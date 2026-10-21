<?php

declare(strict_types=1);

namespace App\Services\MarketData\Alpaca;

use App\Support\Mcp\Decimal;
use App\Support\Mcp\ExactJsonNumbers;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

/** Bounded retrospective SIP sampling. Never returns a current execution observation. */
final class HistoricalQuotes
{
    public const URL = 'https://data.alpaca.markets/v2/stocks/quotes';

    public const CONTRACT = 'alpaca_sip_latest_historical_quote_shares_v1';

    /** Preserve nanoseconds: Carbon's microseconds alone cannot reject a quote 1ns after a cutoff. */
    public static function timestamp(string $value): string
    {
        if (! preg_match('/^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})(?:\.(\d{1,9}))?Z$/D', $value, $m) ||
            CarbonImmutable::parse($m[1].'Z')->format('Y-m-d\TH:i:s') !== $m[1]) {
            throw new \InvalidArgumentException('Invalid UTC quote time.');
        }

        return $m[1].'.'.str_pad($m[2] ?? '', 9, '0').'Z';
    }

    public function at(string $symbol, CarbonImmutable $cutoff): array
    {
        $result = ['provider' => self::URL, 'contract' => self::CONTRACT, 'symbol' => $symbol,
            'feed' => 'sip', 'size_unit' => 'shares', 'selection' => 'newest_record_in_preceding_20_seconds',
            'cutoff' => $cutoff->utc()->toISOString(), 'retrieved_at' => CarbonImmutable::now()->toISOString(),
            'complete' => false, 'error' => null, 'quote' => null];
        $error = match (true) {
            ! config('alpaca_data.enabled') || ! config('alpaca_data.historical_quote_replay_enabled') => 'disabled',
            ! is_string(config('alpaca_data.key')) || config('alpaca_data.key') === '' || ! is_string(config('alpaca_data.secret')) || config('alpaca_data.secret') === '' => 'credentials_missing',
            ! preg_match('/^[A-Z][A-Z0-9.-]{0,15}$/D', $symbol) => 'invalid_symbol',
            $cutoff->gte(CarbonImmutable::now()->subMinutes(20)) => 'completed_history_only',
            $cutoff->toDateString() < '2025-11-03' => 'historical_size_unit_unreviewed',
            default => null,
        };
        if ($error !== null) {
            return array_replace($result, ['error' => $error]);
        }
        try {
            $response = Http::withHeaders(['APCA-API-KEY-ID' => config('alpaca_data.key'), 'APCA-API-SECRET-KEY' => config('alpaca_data.secret')])
                ->timeout(20)->connectTimeout(5)->withOptions(['allow_redirects' => false, 'progress' => function ($total, $downloaded): void {
                    if ($downloaded > 65536) {
                        throw new \RuntimeException('Response limit.');
                    }
                }])->get(self::URL, ['symbols' => $symbol, 'start' => $cutoff->subSeconds(20)->utc()->toISOString(),
                    'end' => $cutoff->utc()->toISOString(), 'limit' => 1, 'sort' => 'desc', 'feed' => 'sip',
                    'asof' => $cutoff->setTimezone('America/New_York')->toDateString(), 'currency' => 'USD']);
            $result['retrieved_at'] = CarbonImmutable::now()->toISOString();
            if (! $response->successful()) {
                return array_replace($result, ['error' => match ($response->status()) {
                    401 => 'authentication_failed', 403 => 'access_denied', 429 => 'rate_limited', default => 'http_failure',
                }]);
            }
            if (strlen($response->body()) > 65536) {
                throw new \InvalidArgumentException('Response limit.');
            }
            $body = ExactJsonNumbers::decode($response->body());
            if (array_diff(array_keys($body), ['quotes', 'next_page_token', 'currency']) || ! is_array($body['quotes'] ?? null) ||
                ! array_key_exists('next_page_token', $body) || (isset($body['currency']) && $body['currency'] !== 'USD') ||
                (($body['next_page_token'] ?? null) !== null && (! is_string($body['next_page_token']) || strlen($body['next_page_token']) > 4096)) ||
                array_diff(array_keys($body['quotes']), [$symbol])) {
                throw new \InvalidArgumentException('Unreviewed quote envelope.');
            }
            $rows = $body['quotes'][$symbol] ?? [];
            if (! is_array($rows) || ! array_is_list($rows) || count($rows) > 1) {
                throw new \InvalidArgumentException('Unreviewed selection.');
            }
            // A token is expected when more older records exist. Descending order + limit 1 selects only the newest;
            // this is complete sampling, explicitly not a claim to have downloaded every quote in the interval.
            if ($rows === [] && $body['next_page_token'] !== null) {
                throw new \InvalidArgumentException('Missing newest record.');
            }
            if ($rows !== []) {
                $q = $rows[0];
                if (! is_array($q) || array_diff(array_keys($q), ['ap', 'as', 'ax', 'bp', 'bs', 'bx', 'c', 't', 'z']) || count($q) !== 9) {
                    throw new \InvalidArgumentException('Unreviewed quote fields.');
                }
                $stamp = self::timestamp($q['t']);
                if ($stamp > self::timestamp($cutoff->utc()->toISOString()) || $stamp < self::timestamp($cutoff->subSeconds(20)->utc()->toISOString()) ||
                    ! is_array($q['c']) || ! array_is_list($q['c']) || array_filter($q['c'], fn ($c) => ! is_string($c)) ||
                    ! is_string($q['ax']) || ! is_string($q['bx']) || ! in_array($q['z'], ['A', 'B', 'C'], true)) {
                    throw new \InvalidArgumentException('Invalid quote context.');
                }
                foreach (['ap', 'bp', 'as', 'bs'] as $key) {
                    if (! is_string($q[$key]) || ! preg_match('/^\d{1,12}(?:\.\d{1,8})?$/D', $q[$key])) {
                        throw new \InvalidArgumentException('Invalid decimal.');
                    }
                }
                if (! ctype_digit($q['as']) || ! ctype_digit($q['bs']) || Decimal::from($q['bp'])->compareTo(Decimal::from('0')) <= 0 ||
                    Decimal::from($q['ap'])->compareTo(Decimal::from($q['bp'])) < 0) {
                    throw new \InvalidArgumentException('Invalid quote prices or sizes.');
                }
                $result['quote'] = ['timestamp_ns' => $stamp, 'bid' => $q['bp'], 'ask' => $q['ap'], 'bid_size' => $q['bs'], 'ask_size' => $q['as'],
                    'conditions' => $q['c'], 'size_unit' => 'shares', 'feed' => 'sip'];
            }

            return array_replace($result, ['complete' => true, 'response_hash' => hash('sha256', $response->body())]);
        } catch (\Throwable) {
            return array_replace($result, ['error' => 'transport_or_unreviewed_response', 'quote' => null]);
        }
    }
}
