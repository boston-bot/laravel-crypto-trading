<?php

namespace Tests\Feature\PaperTrading;

use App\Services\MarketData\Alpaca\HistoricalQuotes;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Tests\Support\AlpacaReferenceFixture;
use Tests\TestCase;

class HistoricalQuotesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-20T14:00:00Z'));
        AlpacaReferenceFixture::enable();
        config(['alpaca_data.historical_quote_replay_enabled' => true]);
    }

    private function body(string $time = '2026-10-19T14:00:00.000000000Z'): string
    {
        return '{"quotes":{"DEMO":[{"t":"'.$time.'","bp":100.12345678,"ap":100.22345678,"bs":200,"as":100,"ax":"V","bx":"V","c":["R"],"z":"C"}]},"next_page_token":"older-records"}';
    }

    public function test_newest_selection_retains_exact_decimals_nanoseconds_and_does_not_paginate_older_quotes(): void
    {
        Http::swap((new Factory)->preventStrayRequests());
        Http::fake([HistoricalQuotes::URL.'*' => Http::response($this->body())]);
        $r = app(HistoricalQuotes::class)->at('DEMO', CarbonImmutable::parse('2026-10-19T14:00:00Z'));
        $this->assertTrue($r['complete'], json_encode($r));
        $this->assertSame('100.12345678', $r['quote']['bid']);
        $this->assertSame('200', $r['quote']['bid_size']);
        $this->assertSame('shares', $r['quote']['size_unit']);
        Http::assertSentCount(1);
        Http::assertSent(fn ($q) => $q['sort'] === 'desc' && $q['limit'] === 1 && $q['feed'] === 'sip' && $q['asof'] === '2026-10-19' && $q['end'] === '2026-10-19T14:00:00.000000Z');
    }

    public function test_a_quote_one_nanosecond_after_cutoff_or_outside_age_window_is_rejected(): void
    {
        foreach (['2026-10-19T14:00:00.000000001Z', '2026-10-19T13:59:39.999999999Z'] as $time) {
            Http::swap((new Factory)->preventStrayRequests());
            Http::fake([HistoricalQuotes::URL.'*' => Http::response($this->body($time))]);
            $r = app(HistoricalQuotes::class)->at('DEMO', CarbonImmutable::parse('2026-10-19T14:00:00Z'));
            $this->assertFalse($r['complete']);
            $this->assertNull($r['quote']);
        }
    }

    public function test_empty_selection_is_unknown_and_forbidden_rate_limit_and_extra_symbols_never_supply_prices(): void
    {
        Http::swap((new Factory)->preventStrayRequests());
        Http::fake([HistoricalQuotes::URL.'*' => Http::response('{"quotes":{},"next_page_token":null}')]);
        $r = app(HistoricalQuotes::class)->at('DEMO', CarbonImmutable::parse('2026-10-19T14:00:00Z'));
        $this->assertTrue($r['complete']);
        $this->assertNull($r['quote']);
        foreach ([403 => 'access_denied', 429 => 'rate_limited'] as $status => $error) {
            Http::swap((new Factory)->preventStrayRequests());
            Http::fake([HistoricalQuotes::URL.'*' => Http::response('private-error-body', $status)]);
            $r = app(HistoricalQuotes::class)->at('DEMO', CarbonImmutable::parse('2026-10-19T14:00:00Z'));
            $this->assertSame($error, $r['error']);
            $this->assertNull($r['quote']);
            $this->assertStringNotContainsString('private-error-body', json_encode($r));
        }
        Http::swap((new Factory)->preventStrayRequests());
        Http::fake([HistoricalQuotes::URL.'*' => Http::response(str_replace('DEMO', 'OTHER', $this->body()))]);
        $this->assertFalse(app(HistoricalQuotes::class)->at('DEMO', CarbonImmutable::parse('2026-10-19T14:00:00Z'))['complete']);
    }

    public function test_gate_recent_data_and_unreviewed_size_eras_refuse_network_access(): void
    {
        $client = app(HistoricalQuotes::class);
        $this->assertSame('historical_size_unit_unreviewed', $client->at('DEMO', CarbonImmutable::parse('2025-11-02T14:00:00Z'))['error']);
        $this->assertSame('completed_history_only', $client->at('DEMO', CarbonImmutable::now())['error']);
        config(['alpaca_data.historical_quote_replay_enabled' => false]);
        $this->assertSame('disabled', $client->at('DEMO', CarbonImmutable::parse('2026-10-19T14:00:00Z'))['error']);
        Http::assertNothingSent();
    }
}
