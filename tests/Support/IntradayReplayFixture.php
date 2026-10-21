<?php

namespace Tests\Support;

use App\Services\PaperTrading\SignalExecutionPolicies;
use App\Services\PaperTrading\TradingCosts;
use App\Support\Mcp\ExactJsonNumbers;
use Carbon\CarbonImmutable;

final class IntradayReplayFixture
{
    public static function settings(): array
    {
        return SignalExecutionPolicies::DEFAULTS + ['cost_schedule' => TradingCosts::reviewed(), 'daily_loss_pause_pct' => '2', 'cash_pct' => '0', 'active_pct' => '100'];
    }

    public static function quote(string $at, string $bid = '101.10', string $ask = '101.20', string $size = '100'): array
    {
        $time = CarbonImmutable::parse($at)->subMilliseconds(100)->format('Y-m-d\TH:i:s.u').'000Z';

        return ['timestamp_ns' => $time, 'bid' => $bid, 'ask' => $ask, 'bid_size' => $size, 'ask_size' => $size, 'conditions' => ['R'], 'feed' => 'sip', 'size_unit' => 'shares'];
    }

    public static function frame(string $at, string $kind = 'decision', ?array $quote = null): array
    {
        return ['at' => $at, 'kind' => $kind, 'quotes' => ['DEMO' => $quote ?? self::quote($at)]];
    }

    public static function data(?array $frames = null): array
    {
        $asOf = CarbonImmutable::parse('2026-10-19T13:30:00Z');
        $symbol = AlpacaReferenceFixture::series([...array_map(fn ($i) => 5000 + $i * 20, range(0, 254)), ...array_fill(0, 20, 10060)], asOf: $asOf);
        $spy = AlpacaReferenceFixture::series(array_map(fn ($i) => 30000 + $i * 10, range(0, 274)), asOf: $asOf);
        $normalize = fn ($rows) => array_map(function ($json) {
            $b = ExactJsonNumbers::decode($json);

            return [CarbonImmutable::parse($b['t'])->setTimezone('America/New_York')->toDateString(), $b['o'], $b['h'], $b['l'], $b['c'], $b['v'], $b['vw'], $b['n']];
        }, $rows);

        return ['complete' => true, 'date' => '2026-10-19', 'symbols' => ['DEMO'], 'previous_session' => '2026-10-16', 'window_end' => '2026-10-19T14:30:00Z',
            'session' => ['date' => '2026-10-19', 'opens_at' => '2026-10-19T13:30:00Z', 'closes_at' => '2026-10-19T20:00:00Z'],
            'daily_reads' => ['DEMO' => ['adjustment' => 'raw', 'bars' => $normalize($symbol)], 'SPY' => ['adjustment' => 'raw', 'bars' => $normalize($spy)]],
            'frames' => $frames ?? [self::frame('2026-10-19T13:45:00Z'), self::frame('2026-10-19T13:45:01Z', 'fill'),
                self::frame('2026-10-19T13:50:00Z', 'decision', self::quote('2026-10-19T13:50:00Z', '98', '98.10')),
                self::frame('2026-10-19T13:50:01Z', 'fill', self::quote('2026-10-19T13:50:01Z', '98', '98.10'))]];
    }
}
