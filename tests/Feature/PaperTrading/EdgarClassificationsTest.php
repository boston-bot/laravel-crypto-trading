<?php

namespace Tests\Feature\PaperTrading;

use App\Services\MarketData\Sec\EdgarClassifications as Edgar;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Tests\Support\EdgarFixture as X;
use Tests\TestCase;

class EdgarClassificationsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-19T14:00:00Z'));
        X::enable();
    }

    private function read(string $symbol = 'DEMO'): array
    {
        return app(Edgar::class)->read($symbol, CarbonImmutable::now());
    }

    public function test_plain_etf_is_confirmed_in_its_latest_amended_report_using_declared_identity_and_get_only(): void
    {
        X::fake(X::mappings() + [
            X::submissionsUrl(X::FUND_CIK) => Http::response(X::submissions(X::FUND_CIK, 'investment', [], [
                ['0001000001-25-000100', 'N-CEN', '2025-05-31', '2025-08-01T20:00:00.000Z'],
                ['0001000001-26-000100', 'N-CEN', '2026-05-31', '2026-08-01T20:00:00.000Z'],
                ['0001000001-26-000200', 'N-CEN/A', '2026-05-31', '2026-09-01T20:00:00.000Z'],
            ])),
            X::documentUrl(X::FUND_CIK, '0001000001-26-000200') => Http::response(X::ncen(X::FUND_CIK, '2026-05-31', [X::series(['Exchange-Traded Fund'])], 'N-CEN/A')),
        ]);
        $read = $this->read();
        $this->assertTrue($read['complete']);
        $this->assertSame('registered_fund_ncen', $read['basis']);
        $this->assertFalse($read['multiple_or_inverse']);
        $this->assertSame('0001000001-26-000200', $read['fund']['accession']);
        $this->assertSame(['Exchange-Traded Fund', 'Index Fund'], $read['fund']['fund_types']);
        $this->assertSame('2026-05-31', $read['fund']['report_period_end']);
        $this->assertSame('2026-09-01T20:00:00.000000Z', $read['source_as_of']);
        $this->assertSame(4, $read['requests']);
        Http::assertSentCount(4);
        Http::assertSent(fn ($r) => $r->method() === 'GET' && $r->hasHeader('User-Agent', 'Synthetic paper monitor ops@example.test') && ! $r->hasHeader('Authorization'));
        $this->assertStringNotContainsString('Synthetic adviser', json_encode($read, JSON_THROW_ON_ERROR));
    }

    public function test_item_c3c_and_rule_18f4_leveraged_inverse_answers_each_mark_the_series_and_mappings_are_read_once(): void
    {
        X::fake(X::mappings([['LEV', 'S000000002', 'C000000002'], ['INV', 'S000000003', 'C000000003'], ['DEMO', 'S000000001', 'C000000001']]) + [
            X::submissionsUrl(X::FUND_CIK) => Http::response(X::submissions(X::FUND_CIK, 'investment', [], [['0001000001-26-000100', 'N-CEN', '2026-05-31']])),
            X::documentUrl(X::FUND_CIK, '0001000001-26-000100') => Http::response(X::ncen(X::FUND_CIK, '2026-05-31', [
                X::series(['Exchange-Traded Fund', Edgar::MULTIPLE_OR_INVERSE], [], 'S000000002', [['C000000002', 'LEV']]),
                X::series(['Exchange-Traded Fund'], ['Rule 18f-4 (17 CFR 270.18f-4)', Edgar::LEVERAGED_INVERSE_RULE], 'S000000003', [['C000000003', 'INV']]),
                X::series(['Exchange-Traded Fund'], ['Rule 18f-4 (17 CFR 270.18f-4)', 'Rule 18f-4(c)(4) (17CFR 270.18f-4(c)(4))']),
            ])),
        ]);
        $reads = app(Edgar::class)->readMany(['LEV', 'INV', 'DEMO'], CarbonImmutable::now());
        $this->assertTrue($reads['LEV']['multiple_or_inverse']);
        $this->assertTrue($reads['INV']['multiple_or_inverse']);
        $this->assertFalse($reads['DEMO']['multiple_or_inverse']); // Limited-derivatives-user reliance is not an index multiple.
        Http::assertSentCount(2 + 3 * 2);
    }

    public function test_newer_report_without_the_series_falls_back_while_reports_outside_the_window_are_never_fetched(): void
    {
        X::fake(X::mappings() + [
            X::submissionsUrl(X::FUND_CIK) => Http::response(X::submissions(X::FUND_CIK, 'investment', [], [
                ['0001000001-24-000100', 'N-CEN', '2025-05-31', '2025-07-01T20:00:00.000Z'],
                ['0001000001-26-000100', 'N-CEN', '2026-05-31'],
                ['0001000001-26-000300', 'N-CEN', '2026-08-31', '2026-10-10T20:00:00.000Z'],
            ])),
            X::documentUrl(X::FUND_CIK, '0001000001-26-000300') => Http::response(X::ncen(X::FUND_CIK, '2026-08-31', [X::series(id: 'S000000009', classes: [['C000000009', 'OTHR']])])),
            X::documentUrl(X::FUND_CIK, '0001000001-26-000100') => Http::response(X::ncen(X::FUND_CIK, '2026-05-31', [X::series()])),
        ]);
        $read = $this->read();
        $this->assertSame('0001000001-26-000100', $read['fund']['accession']);
        $this->assertSame(2, $read['fund']['filings_examined']);
        X::fake(X::mappings() + [X::submissionsUrl(X::FUND_CIK) => Http::response(X::submissions(X::FUND_CIK, 'investment', [], [['0001000001-24-000100', 'N-CEN', '2025-05-31']]))]);
        $stale = $this->read();
        $this->assertSame(['unclassified', 'series_not_in_recent_ncen', null], [$stale['basis'], $stale['reason'], $stale['multiple_or_inverse']]);
        Http::assertSentCount(3);
    }

    public function test_class_ticker_unknown_values_and_identity_mismatches_fail_closed(): void
    {
        X::fake(X::plainFund([X::series(classes: [['C000000001', 'OTHR']])]));
        $this->assertSame('class_ticker_not_confirmed_in_ncen', $this->read()['reason']);
        X::fake(X::plainFund([X::series(['Exchange-Traded Fund', 'Leveraged Fund'])]));
        $this->assertSame(['unclassified', 'unreviewed_ncen_value'], [$this->read()['basis'], $this->read()['reason']]);
        foreach ([
            X::ncen(X::FUND_CIK, '2026-05-31', [X::series()], 'N-CEN/A'),
            X::ncen(X::FUND_CIK + 1, '2026-05-31', [X::series()]),
            X::ncen(X::FUND_CIK, '2026-06-30', [X::series()]),
            X::ncen(X::FUND_CIK, '2026-05-31', [X::series(), X::series()]),
            '<?xml version="1.0"?><!DOCTYPE x [<!ENTITY a "aaaa">]>'.substr(X::ncen(X::FUND_CIK, '2026-05-31', [X::series()]), 39),
            str_replace(Edgar::NAMESPACE, 'urn:other', X::ncen(X::FUND_CIK, '2026-05-31', [X::series()])),
            substr(X::ncen(X::FUND_CIK, '2026-05-31', [X::series()]), 0, -30),
        ] as $i => $document) {
            X::fake(array_replace(X::plainFund(), [X::documentUrl(X::FUND_CIK, '0001000001-26-000100') => Http::response($document)]));
            $read = $this->read();
            $this->assertFalse($read['complete'], (string) $i);
            $this->assertSame('invalid_or_unreviewed_response', $read['error'], (string) $i);
            $this->assertNull($read['multiple_or_inverse'], (string) $i);
        }
    }

    public function test_only_a_sole_listed_operating_ticker_qualifies_and_bank_note_shelves_stay_unclassified(): void
    {
        X::fake(X::mappings(companies: [['SOLO', X::ISSUER_CIK], ['BANK', X::BANK_CIK], ['LEVX', X::BANK_CIK], ['TWIN', 1000004], ['TWIN', 1000005], ['ODD', 1000006]]) + [
            X::submissionsUrl(X::ISSUER_CIK) => Http::response(X::submissions(X::ISSUER_CIK, 'operating', ['SOLO'], [])),
            X::submissionsUrl(X::BANK_CIK) => Http::response(X::submissions(X::BANK_CIK, 'operating', ['BANK', 'LEVX'], [])),
            X::submissionsUrl(1000006) => Http::response(X::submissions(1000006, 'other', ['ODD'], [])),
        ]);
        $reads = app(Edgar::class)->readMany(['SOLO', 'LEVX', 'BANK', 'TWIN', 'ODD', 'NONE'], CarbonImmutable::now());
        $this->assertSame(['operating_issuer_sole_listed_security', null], [$reads['SOLO']['basis'], $reads['SOLO']['reason']]);
        $this->assertNull($reads['SOLO']['multiple_or_inverse']);
        $this->assertSame('issuer_has_multiple_listed_securities', $reads['LEVX']['reason']);
        $this->assertSame('issuer_has_multiple_listed_securities', $reads['BANK']['reason']);
        $this->assertSame('ambiguous_ticker_mapping', $reads['TWIN']['reason']);
        $this->assertSame('registrant_not_operating_company', $reads['ODD']['reason']);
        $this->assertSame('ticker_not_in_sec_mappings', $reads['NONE']['reason']);
        foreach ($reads as $read) {
            $this->assertTrue($read['complete']);
        }
    }

    public function test_disabled_missing_identity_and_provider_failures_are_incomplete_without_bodies(): void
    {
        X::fake([]);
        config(['sec_edgar.user_agent' => 'no-contact-address']);
        $this->assertSame('declared_user_agent_missing', $this->read()['error']);
        config(['sec_edgar.enabled' => false]);
        $this->assertSame('disabled', $this->read()['error']);
        Http::assertNothingSent();
        X::enable();
        foreach ([403 => 'access_denied', 429 => 'rate_limited', 500 => 'http_failure'] as $status => $error) {
            X::fake([Edgar::FUND_TICKERS => Http::response('secret provider body', $status)]);
            $read = $this->read();
            $this->assertSame($error, $read['error']);
            $this->assertStringNotContainsString('secret provider body', json_encode($read, JSON_THROW_ON_ERROR));
        }
        config(['sec_edgar.max_document_bytes' => 100]);
        X::fake(X::plainFund());
        $this->assertSame('response_limit', $this->read()['error']); // Declared filing size exceeds the bound before download.
        Http::assertSentCount(3);
        config(['sec_edgar.max_document_bytes' => 5000]);
        $many = array_map(fn ($i) => X::series(id: sprintf('S%09d', 100 + $i), classes: [[sprintf('C%09d', 100 + $i), 'X'.$i]]), range(1, 12));
        X::fake(X::plainFund([X::series(), ...$many]));
        $this->assertSame('response_limit', $this->read()['error']); // Actual body exceeds the bound despite a smaller declared size.
    }

    public function test_bounded_filing_and_history_scans_report_limits_instead_of_absence(): void
    {
        config(['sec_edgar.max_ncen_documents' => 1]);
        X::fake(X::mappings() + [
            X::submissionsUrl(X::FUND_CIK) => Http::response(X::submissions(X::FUND_CIK, 'investment', [], [
                ['0001000001-26-000100', 'N-CEN', '2026-05-31'], ['0001000001-26-000300', 'N-CEN', '2026-08-31', '2026-10-10T20:00:00.000Z']])),
            X::documentUrl(X::FUND_CIK, '0001000001-26-000300') => Http::response(X::ncen(X::FUND_CIK, '2026-08-31', [])),
        ]);
        $this->assertSame('filing_limit', $this->read()['error']);
        config(['sec_edgar.max_ncen_documents' => 8]);
        $page = 'CIK'.str_pad((string) X::FUND_CIK, 10, '0', STR_PAD_LEFT).'-submissions-001.json';
        $older = X::submissions(X::FUND_CIK, 'investment', [], [['0001000001-25-000100', 'N-CEN', '2025-11-30', '2026-01-20T20:00:00.000Z']])['filings']['recent'];
        $recent = X::submissions(X::FUND_CIK, 'investment', [], [], [['name' => $page, 'filingCount' => 2, 'filingFrom' => '2025-01-01', 'filingTo' => '2026-09-30']]);
        X::fake(X::mappings() + [
            X::submissionsUrl(X::FUND_CIK) => Http::response($recent),
            Edgar::SUBMISSIONS.$page => Http::response($older),
            X::documentUrl(X::FUND_CIK, '0001000001-25-000100') => Http::response(X::ncen(X::FUND_CIK, '2025-11-30', [X::series()])),
        ]);
        $this->assertSame('0001000001-25-000100', $this->read()['fund']['accession']);
        config(['sec_edgar.max_history_pages' => 0]);
        $this->assertSame('history_page_limit', $this->read()['error']);
    }
}
