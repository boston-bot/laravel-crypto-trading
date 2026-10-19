<?php

namespace Tests\Support;

use App\Services\MarketData\Alpaca\DailyBars;
use Carbon\CarbonImmutable;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/** Entirely synthetic Alpaca-shaped data. No real symbols' prices, credentials or account identifiers. */
final class AlpacaReferenceFixture
{
    public static function enable(): void
    {
        config(['alpaca_data.enabled' => true, 'alpaca_data.reference_enabled' => true, 'alpaca_data.key' => 'PKSYNTHETIC',
            'alpaca_data.secret' => 'synthetic-secret', 'alpaca_data.account_environment' => 'paper']);
    }

    /** Weekday bars ending the New York day before $asOf, oldest first, as raw JSON object strings. */
    public static function bars(int $sessions, string $close = '100.25', string $volume = '500000', ?CarbonImmutable $asOf = null, array $override = []): array
    {
        $day = ($asOf ?? CarbonImmutable::now())->setTimezone('America/New_York')->startOfDay()->subDay();
        $rows = [];
        while (count($rows) < $sessions) {
            if (! $day->isWeekend()) {
                $t = $day->utc()->format('Y-m-d\TH:i:s\Z');
                $fields = array_replace(['c' => $close, 'h' => $close, 'l' => $close, 'n' => '1200', 'o' => $close, 't' => '"'.$t.'"', 'v' => $volume, 'vw' => $close], $override);
                $rows[] = '{'.implode(',', array_map(fn ($k, $v) => '"'.$k.'":'.$v, array_keys($fields), $fields)).'}';
            }
            $day = $day->subDay();
        }

        return array_reverse($rows);
    }

    /** @param array<string, list<string>> $bars symbol => raw bar objects */
    public static function body(array $bars, ?string $next = null): string
    {
        $symbols = array_map(fn ($symbol, $rows) => '"'.$symbol.'":['.implode(',', $rows).']', array_keys($bars), $bars);

        return '{"bars":{'.implode(',', $symbols).'},"next_page_token":'.($next === null ? 'null' : '"'.$next.'"').',"currency":"USD"}';
    }

    public static function asset(string $symbol, string $exchange = 'NASDAQ', string $class = 'us_equity', string $status = 'active'): array
    {
        return ['id' => '00000000-0000-4000-8000-000000000001', 'class' => $class, 'exchange' => $exchange, 'symbol' => $symbol,
            'name' => 'Synthetic fixture issuer', 'status' => $status, 'tradable' => true, 'marginable' => true, 'shortable' => false,
            'easy_to_borrow' => false, 'fractionable' => true, 'attributes' => []];
    }

    /**
     * @param  callable(array $query): (string|Response|PromiseInterface)  $bars
     * @param  array<string, array|int>  $assets  symbol => asset body, or an HTTP status
     */
    public static function fake(callable $bars, array $assets = []): void
    {
        Http::swap((new Factory)->preventStrayRequests());
        Http::fake([
            DailyBars::URL.'*' => function ($request) use ($bars) {
                parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $query);
                $result = $bars($query);

                return is_string($result) ? Http::response($result, 200, ['Content-Type' => 'application/json']) : $result;
            },
            'https://paper-api.alpaca.markets/v2/assets/*' => fn ($request) => self::assetResponse($request, $assets),
            'https://api.alpaca.markets/v2/assets/*' => fn ($request) => self::assetResponse($request, $assets),
        ]);
    }

    private static function assetResponse($request, array $assets)
    {
        $symbol = rawurldecode(basename(parse_url($request->url(), PHP_URL_PATH)));
        $asset = $assets[$symbol] ?? 404;

        return is_int($asset) ? Http::response(['message' => 'synthetic'], $asset) : Http::response($asset);
    }
}
