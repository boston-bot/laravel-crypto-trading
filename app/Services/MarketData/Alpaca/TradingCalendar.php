<?php

declare(strict_types=1);

namespace App\Services\MarketData\Alpaca;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Read-only US equity trading calendar. One fixed GET path on the configured account host; times are New York
 * local. Dates the provider omits inside a complete range are days without a regular session.
 */
final class TradingCalendar
{
    public const CONTRACT = 'alpaca_calendar_v2_v1';

    private const FIELDS = ['close', 'date', 'open', 'session_close', 'session_open', 'settlement_date'];

    public function read(string $start, string $end): array
    {
        $host = Listings::HOSTS[config('alpaca_data.account_environment')] ?? null;
        $result = ['provider' => ($host ?? 'unconfigured').'/v2/calendar', 'contract' => self::CONTRACT, 'start' => $start, 'end' => $end,
            'retrieved_at' => CarbonImmutable::now()->toISOString(), 'complete' => false, 'error' => null, 'sessions' => []];
        $error = match (true) {
            ! config('alpaca_data.enabled') || ! config('alpaca_data.calendar_enabled') => 'disabled',
            $host === null => 'account_environment_invalid',
            ! is_string(config('alpaca_data.key')) || config('alpaca_data.key') === '' || ! is_string(config('alpaca_data.secret')) || config('alpaca_data.secret') === '' => 'credentials_missing',
            ! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $start) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $end) || $start > $end
                || CarbonImmutable::parse($start)->diffInDays(CarbonImmutable::parse($end)) > 120 => 'invalid_range',
            default => null,
        };
        if ($error !== null) {
            return array_replace($result, ['error' => $error]);
        }
        try {
            $response = Http::withHeaders(['APCA-API-KEY-ID' => config('alpaca_data.key'), 'APCA-API-SECRET-KEY' => config('alpaca_data.secret')])
                ->timeout(10)->connectTimeout(5)->withOptions(['allow_redirects' => false])->get($host.'/v2/calendar', ['start' => $start, 'end' => $end]);
            $result['retrieved_at'] = CarbonImmutable::now()->toISOString();
            if (! $response->successful()) {
                return array_replace($result, ['error' => match ($response->status()) {
                    401 => 'authentication_failed', 403 => 'access_denied', 429 => 'rate_limited', default => 'http_failure',
                }]);
            }
            if (strlen($response->body()) > 256 * 1024) {
                return array_replace($result, ['error' => 'response_limit']);
            }
            $rows = $response->json();
            if (! is_array($rows) || ! array_is_list($rows)) {
                throw new \InvalidArgumentException('Unreviewed envelope.');
            }
            $previous = null;
            foreach ($rows as $row) {
                $session = $this->session($row, $start, $end);
                if ($previous !== null && $session['date'] <= $previous) {
                    throw new \InvalidArgumentException('Unordered calendar.');
                }
                $previous = $session['date'];
                $result['sessions'][] = $session;
            }

            return array_replace($result, ['complete' => true]);
        } catch (ConnectionException) {
            return array_replace($result, ['error' => 'transport_unavailable', 'sessions' => []]);
        } catch (\InvalidArgumentException|\TypeError|\ValueError) {
            return array_replace($result, ['error' => 'invalid_or_unreviewed_response', 'sessions' => []]);
        } catch (\Throwable) {
            return array_replace($result, ['error' => 'transport_or_response_failure', 'sessions' => []]);
        }
    }

    /** @return array{date: string, opens_at: string, closes_at: string, status: string} */
    private function session(mixed $row, string $start, string $end): array
    {
        if (! is_array($row) || array_is_list($row)) {
            throw new \InvalidArgumentException('Malformed session.');
        }
        $keys = array_keys($row);
        sort($keys);
        if ($keys !== self::FIELDS || ! is_string($row['date']) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $row['date']) || $row['date'] < $start || $row['date'] > $end) {
            throw new \InvalidArgumentException('Unreviewed session fields.');
        }
        foreach (['open', 'close'] as $key) {
            if (! is_string($row[$key]) || ! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/D', $row[$key])) {
                throw new \InvalidArgumentException('Invalid session time.');
            }
        }
        $open = CarbonImmutable::parse($row['date'].' '.$row['open'], 'America/New_York');
        $close = CarbonImmutable::parse($row['date'].' '.$row['close'], 'America/New_York');
        // Reviewed US equity hours: regular sessions open at 09:30 and close at 16:00 or earlier on early-close days.
        if ($open->isWeekend() || $row['open'] !== '09:30' || $close->lte($open) || $row['close'] > '16:00') {
            throw new \InvalidArgumentException('Unreviewed session hours.');
        }

        return ['date' => $row['date'], 'opens_at' => $open->utc()->toIso8601String(), 'closes_at' => $close->utc()->toIso8601String(),
            'status' => $row['close'] === '16:00' ? 'regular' : 'early_close'];
    }
}
