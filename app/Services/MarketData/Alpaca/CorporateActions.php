<?php

declare(strict_types=1);

namespace App\Services\MarketData\Alpaca;

use App\Support\Mcp\Decimal;
use App\Support\Mcp\ExactJsonNumbers;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/** Narrow read-only data transport. No configurable host/path, broker account, order or mutation methods. */
final class CorporateActions
{
    public const URL = 'https://data.alpaca.markets/v1/corporate-actions';

    public const GROUPS = ['forward_splits', 'reverse_splits', 'unit_splits', 'cash_dividends', 'stock_dividends', 'spin_offs',
        'cash_mergers', 'stock_mergers', 'stock_and_cash_mergers', 'redemptions', 'name_changes', 'worthless_removals',
        'rights_distributions', 'partial_calls', 'reorganizations', 'capital_gains_distributions'];

    private const SYMBOLS = ['symbol', 'old_symbol', 'new_symbol', 'alternate_symbol', 'source_symbol', 'acquirer_symbol', 'acquiree_symbol'];

    private const DATES = ['process_date', 'ex_date', 'effective_date', 'record_date', 'payable_date', 'due_bill_redemption_date',
        'due_bill_on_date', 'due_bill_off_date', 'expiration_date'];

    private const RATES = ['rate', 'new_rate', 'old_rate', 'alternate_rate', 'source_rate', 'acquirer_rate', 'acquiree_rate', 'cash_rate'];

    public function read(string $symbol, string $start, string $end): array
    {
        $result = ['provider' => self::URL, 'contract' => 'alpaca_actions_v1_process_date_all_quality', 'symbol' => $symbol,
            'start' => $start, 'end' => $end, 'date_basis' => 'process_date', 'data_quality' => 'all', 'types' => 'all',
            'source_as_of' => null, 'retrieved_at' => null, 'complete' => false, 'pages' => 0, 'events' => [],
            'error' => null, 'publication_delay_possible' => true, 'corporate_actions_clear' => null];
        if (! config('alpaca_data.enabled')) {
            return array_replace($result, ['error' => 'disabled']);
        }
        if (! is_string(config('alpaca_data.key')) || config('alpaca_data.key') === '' || ! is_string(config('alpaca_data.secret')) || config('alpaca_data.secret') === '') {
            return array_replace($result, ['error' => 'credentials_missing']);
        }
        try {
            if (! preg_match('/^[A-Z][A-Z0-9.-]{0,15}$/D', $symbol) || $this->date($start) > $this->date($end) ||
                CarbonImmutable::parse($start)->diffInDays(CarbonImmutable::parse($end)) > 366) {
                throw new \InvalidArgumentException('Invalid bounded query.');
            }
            $cursor = null;
            $cursors = [];
            $ids = [];
            for ($page = 0; $page < min(20, (int) config('alpaca_data.max_pages')); $page++) {
                $query = ['symbols' => $symbol, 'start' => $start, 'end' => $end, 'data_quality' => 'all', 'limit' => 1000, 'sort' => 'asc'];
                if ($cursor !== null) {
                    $query['page_token'] = $cursor;
                }
                $response = Http::withHeaders(['APCA-API-KEY-ID' => config('alpaca_data.key'), 'APCA-API-SECRET-KEY' => config('alpaca_data.secret')])
                    ->timeout(10)->connectTimeout(5)->withOptions(['allow_redirects' => false, 'progress' => function ($total, $downloaded): void {
                        if ($downloaded > 8 * 1024 * 1024) {
                            throw new \RuntimeException('Response limit.');
                        }
                    }])->get(self::URL, $query);
                $result['retrieved_at'] = CarbonImmutable::now()->toISOString();
                if (! $response->successful()) {
                    $result['error'] = match ($response->status()) {
                        401 => 'authentication_failed', 403 => 'access_denied', 429 => 'rate_limited', default => 'http_failure',
                    };

                    return $result; // Never log provider error bodies or request/credential-bearing exceptions.
                }
                if (strlen($response->body()) > 8 * 1024 * 1024) {
                    throw new \InvalidArgumentException('Response too large.');
                }
                $body = ExactJsonNumbers::decode($response->body());
                if (! isset($body['corporate_actions']) || ! is_array($body['corporate_actions']) || ! array_key_exists('next_page_token', $body)) {
                    throw new \InvalidArgumentException('Incomplete response envelope.');
                }
                foreach ($body['corporate_actions'] as $group => $rows) {
                    if (! in_array($group, self::GROUPS, true) || ! is_array($rows) || ! array_is_list($rows)) {
                        throw new \InvalidArgumentException('Unreviewed action group.');
                    }
                    foreach ($rows as $row) {
                        if (count($result['events']) >= min(10000, (int) config('alpaca_data.max_events'))) {
                            $result['error'] = 'event_limit';

                            return $result;
                        }
                        $event = $this->normalize($group, $row, $symbol, $start, $end);
                        if (isset($ids[$event['id']])) {
                            throw new \InvalidArgumentException('Repeated action ID.');
                        }
                        $ids[$event['id']] = true;
                        $result['events'][] = $event;
                    }
                }
                $result['pages']++;
                $cursor = $body['next_page_token'];
                if ($cursor === null) {
                    $result['complete'] = true; // Complete requested process-date pages, not guaranteed event clearance.

                    return $result;
                }
                if (! is_string($cursor) || $cursor === '' || strlen($cursor) > 4096 || isset($cursors[$cursor])) {
                    throw new \InvalidArgumentException('Invalid pagination.');
                }
                $cursors[$cursor] = true;
            }
            $result['error'] = 'page_limit';
        } catch (ConnectionException) {
            $result['error'] = 'transport_unavailable';
        } catch (\InvalidArgumentException|\JsonException|MathException|\TypeError) {
            $result['error'] = 'invalid_or_unreviewed_response';
        } catch (\Throwable) {
            $result['error'] = 'transport_or_response_failure';
        }

        return $result;
    }

    private function normalize(string $group, array $row, string $symbol, string $start, string $end): array
    {
        if (! is_string($row['id'] ?? null) || ! preg_match('/^[A-Za-z0-9:._-]{1,128}$/D', $row['id'])) {
            throw new \InvalidArgumentException('Unknown event identity.');
        }
        $event = ['id' => $row['id'], 'type' => $group, 'symbols' => [], 'dates' => [], 'rates' => [], 'flags' => [], 'missing_fields' => []];
        foreach (self::SYMBOLS as $key) {
            $value = $row[$key] ?? null;
            if ($value !== null && (! is_string($value) || ! preg_match('/^[A-Z][A-Z0-9.-]{0,15}$/D', $value))) {
                throw new \InvalidArgumentException('Invalid event symbol.');
            }
            $event['symbols'][$key] = $value;
        }
        $symbols = array_filter($event['symbols'], fn ($value) => $value !== null);
        if ($symbols !== [] && ! in_array($symbol, $symbols, true)) {
            throw new \InvalidArgumentException('Wrong-symbol event.');
        }
        if ($symbols === []) {
            $event['missing_fields'][] = 'symbol';
        }
        foreach (self::DATES as $key) {
            $event['dates'][$key] = isset($row[$key]) ? $this->date($row[$key]) : null;
        }
        $process = $event['dates']['process_date'];
        if ($process === null) {
            $event['missing_fields'][] = 'process_date';
        } elseif ($process < $start || $process > $end) {
            throw new \InvalidArgumentException('Event outside process-date interval.');
        }
        foreach (self::RATES as $key) {
            $value = $row[$key] ?? null;
            if ($value !== null) {
                if (! is_string($value) || strlen($value) > 64 || ! preg_match('/^\d+(?:\.\d+)?(?:[eE][+-]?\d{1,2})?$/D', $value)) {
                    throw new \InvalidArgumentException('Invalid exact rate.');
                }
                $value = (string) BigDecimal::of($value);
                if (! preg_match('/^\d{1,12}(?:\.\d{1,18})?$/D', $value)) {
                    throw new \InvalidArgumentException('Rate outside supported precision.');
                }
                $value = (string) Decimal::from($value);
            }
            $event['rates'][$key] = $value;
        }
        foreach (['special', 'foreign'] as $key) {
            $flag = $row[$key] ?? null;
            if ($flag !== null && ! is_bool($flag)) {
                throw new \InvalidArgumentException('Invalid event flag.');
            }
            $event['flags'][$key] = $flag;
        }
        // All-quality records may lack critical fields; preserve rather than suppress that evidence.
        // Required terms follow the official SDK event models; unlisted groups have no reviewed contract.
        $required = match ($group) {
            'forward_splits', 'reverse_splits' => ['ex_date', 'new_rate', 'old_rate'],
            'cash_dividends', 'stock_dividends', 'capital_gains_distributions' => ['ex_date', 'rate'],
            'cash_mergers' => ['effective_date', 'rate'],
            'stock_mergers' => ['effective_date', 'acquirer_rate', 'acquiree_rate'],
            'stock_and_cash_mergers' => ['effective_date', 'acquirer_rate', 'acquiree_rate', 'cash_rate'],
            'spin_offs' => ['ex_date', 'source_rate', 'new_rate'],
            'unit_splits' => ['effective_date', 'old_rate', 'new_rate', 'alternate_rate'],
            'rights_distributions' => ['ex_date', 'rate'],
            'redemptions' => ['rate'],
            'name_changes' => ['old_symbol', 'new_symbol'],
            'worthless_removals' => [],
            default => ['unreviewed_adjustment_contract'],
        };
        foreach ($required as $key) {
            if (($event['dates'][$key] ?? $event['rates'][$key] ?? $event['symbols'][$key] ?? null) === null) {
                $event['missing_fields'][] = $key;
            }
        }
        $event['adjustment_supported'] = false;

        return $event;
    }

    private function date(mixed $value): string
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value)) {
            throw new \InvalidArgumentException('Unknown calendar date.');
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (! $date || $date->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException('Invalid calendar date.');
        }

        return $value;
    }
}
