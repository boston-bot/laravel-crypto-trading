<?php

namespace Tests\Support;

use App\Services\MarketData\Alpaca\CorporateActions;
use App\Services\MarketData\Alpaca\DailyBars;
use App\Services\MarketData\Alpaca\HistoricalQuotes;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

final class ExperimentFixture
{
    public static function enable(): void
    {
        AlpacaReferenceFixture::enable();
        config(['strategy_experiments.enabled' => true, 'alpaca_data.calendar_enabled' => true, 'alpaca_data.historical_quote_replay_enabled' => true]);
    }

    public static function spec(array $changes = []): array
    {
        return array_replace(['name' => 'Synthetic comparison', 'hypothesis' => 'Compare whole-share affordability on identical data.', 'symbols' => ['DEMO'],
            'sessions' => ['2026-10-19', '2026-10-20'], 'baseline' => 'baseline',
            'variants' => [['id' => 'baseline', 'equity' => '500'], ['id' => 'capital', 'equity' => '10000']]], $changes);
    }

    public static function sources(?callable $quoteHook = null): void
    {
        $bars = IntradayReplayFixture::data()['daily_reads'];
        Http::preventStrayRequests();
        Http::fake([
            'https://paper-api.alpaca.markets/v2/calendar*' => function ($q) {
                $dates = array_values(array_filter(['2026-10-16', '2026-10-19', '2026-10-20'], fn ($d) => $d >= $q['start'] && $d <= $q['end']));

                return Http::response(array_map(fn ($date) => ['date' => $date, 'open' => '09:30', 'close' => '16:00', 'session_open' => '04:00', 'session_close' => '20:00', 'settlement_date' => '2026-10-21'], $dates));
            },
            DailyBars::URL.'*' => function ($q) use ($bars) {
                if ($q['end'] >= '2026-10-19') {
                    foreach ($bars as &$read) {
                        $bar = $read['bars'][array_key_last($read['bars'])];
                        $bar[0] = '2026-10-19';
                        $read['bars'][] = $bar;
                    }
                    unset($read);
                }
                $body = [];
                foreach (explode(',', $q['symbols']) as $symbol) {
                    $body[$symbol] = array_map(fn ($b) => '{"t":"'.CarbonImmutable::parse($b[0], 'America/New_York')->utc()->format('Y-m-d\TH:i:s\Z').'","o":'.$b[1].',"h":'.$b[2].',"l":'.$b[3].',"c":'.$b[4].',"v":'.$b[5].',"vw":'.$b[6].',"n":'.$b[7].'}', $bars[$symbol]['bars']);
                }

                return Http::response(AlpacaReferenceFixture::body($body));
            },
            CorporateActions::URL.'*' => Http::response('{"corporate_actions":{},"next_page_token":null}'),
            HistoricalQuotes::URL.'*' => function ($q) use ($quoteHook) {
                if ($quoteHook && ($response = $quoteHook($q))) {
                    return $response;
                }
                $quote = IntradayReplayFixture::quote($q['end']);

                return Http::response(['quotes' => [$q['symbols'] => [['t' => $quote['timestamp_ns'], 'bp' => $quote['bid'], 'ap' => $quote['ask'],
                    'bs' => '100', 'as' => '100', 'ax' => 'V', 'bx' => 'V', 'z' => 'C', 'c' => ['R']]]], 'next_page_token' => 'older']);
            },
        ]);
    }
}
