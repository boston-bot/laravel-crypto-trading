<?php

namespace Tests\Support;

use App\Services\MarketData\Sec\EdgarClassifications as Edgar;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;

/** Synthetic SEC public-filing shapes modeled on the reviewed N-CEN 3.1 schema. No real filer data. */
final class EdgarFixture
{
    public const FUND_CIK = 1000001;

    public const ISSUER_CIK = 1000002;

    public const BANK_CIK = 1000003;

    public static function enable(): void
    {
        config(['sec_edgar.enabled' => true, 'sec_edgar.user_agent' => 'Synthetic paper monitor ops@example.test', 'sec_edgar.request_interval_ms' => 0]);
    }

    public static function fake(array $responses): void
    {
        Http::swap((new Factory)->preventStrayRequests());
        Http::fake($responses);
    }

    public static function mappings(array $funds = [['DEMO', 'S000000001', 'C000000001']], array $companies = [['SOLO', self::ISSUER_CIK], ['BANK', self::BANK_CIK], ['LEVX', self::BANK_CIK]]): array
    {
        $company = [];
        foreach ($companies as $i => [$ticker, $cik]) {
            $company[(string) $i] = ['cik_str' => $cik, 'ticker' => $ticker, 'title' => 'Synthetic registrant'];
        }

        return [Edgar::FUND_TICKERS => Http::response(['fields' => ['cik', 'seriesId', 'classId', 'symbol'],
            'data' => array_map(fn ($f) => [$f[3] ?? self::FUND_CIK, $f[1], $f[2], $f[0]], $funds)]),
            Edgar::COMPANY_TICKERS => Http::response($company)];
    }

    public static function submissionsUrl(int $cik): string
    {
        return Edgar::SUBMISSIONS.'CIK'.str_pad((string) $cik, 10, '0', STR_PAD_LEFT).'.json';
    }

    public static function documentUrl(int $cik, string $accession): string
    {
        return Edgar::ARCHIVES.$cik.'/'.str_replace('-', '', $accession).'/primary_doc.xml';
    }

    /** @param list<array{0:string,1:string,2:string,3?:string,4?:int}> $filings accession, form, report date, accepted at, size */
    public static function submissions(int $cik, string $type, array $tickers, array $filings, array $files = []): array
    {
        $columns = array_fill_keys(['accessionNumber', 'filingDate', 'reportDate', 'acceptanceDateTime', 'form', 'primaryDocument', 'size'], []);
        foreach ([['0001000001-26-000900', '497', '', '2026-10-01T12:00:00.000Z', 1000], ...$filings] as $f) {
            $accepted = $f[3] ?? '2026-08-01T20:00:00.000Z';
            $columns['accessionNumber'][] = $f[0];
            $columns['form'][] = $f[1];
            $columns['reportDate'][] = $f[2];
            $columns['acceptanceDateTime'][] = $accepted;
            $columns['filingDate'][] = substr($accepted, 0, 10);
            $columns['primaryDocument'][] = $f[1] === '497' ? 'synthetic.htm' : 'xslFormN-CEN_X01/primary_doc.xml';
            $columns['size'][] = $f[4] ?? 5000;
        }

        return ['cik' => str_pad((string) $cik, 10, '0', STR_PAD_LEFT), 'entityType' => $type, 'sic' => '', 'name' => 'Synthetic registrant',
            'tickers' => $tickers, 'filings' => ['recent' => $columns, 'files' => $files]];
    }

    /** @param list<array{id:string,classes:list<array{0:string,1:string}>,types:?list<string>,index?:bool,rules:list<string>}> $series */
    public static function ncen(int $cik, string $period, array $series, string $form = 'N-CEN'): string
    {
        $funds = '';
        foreach ($series as $s) {
            $classes = implode('', array_map(fn ($c) => '<sharesOutstanding sharesOutstandingClassName="Synthetic class" sharesOutstandingClassId="'.$c[0].'" sharesOutstandingTickerSymbol="'.$c[1].'"/>', $s['classes']));
            $types = $s['types'] === null ? '<fundType>N/A</fundType>' : '<fundTypes>'.implode('', array_map(fn ($t) => '<fundType>'.$t.'</fundType>', $s['types'])).
                (($s['index'] ?? false) ? '<indexFundInfo fundType="Index Fund"><isIndexFundAffiliated>N</isIndexFundAffiliated><isIndexFundExclusive>N</isIndexFundExclusive></indexFundInfo>' : '').'</fundTypes>';
            $rules = $s['rules'] === [] ? '<relyOnRuleType>N/A</relyOnRuleType>' : '<relyOnRuleTypes>'.implode('', array_map(fn ($r) => '<relyOnRuleType>'.$r.'</relyOnRuleType>', $s['rules'])).'</relyOnRuleTypes>';
            $funds .= '<managementInvestmentQuestion><mgmtInvFundName>Synthetic fund</mgmtInvFundName><mgmtInvSeriesId>'.$s['id'].'</mgmtInvSeriesId>'.
                '<isFirstFilingByFund>N</isFirstFilingByFund><sharesOutstandings>'.$classes.'</sharesOutstandings>'.$types.
                '<isNonDiversifiedCompany>N</isNonDiversifiedCompany>'.$rules.'<investmentAdvisers><investmentAdviser><investmentAdviserName>Synthetic adviser</investmentAdviserName></investmentAdviser></investmentAdvisers></managementInvestmentQuestion>';
        }

        return '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<edgarSubmission xmlns="'.Edgar::NAMESPACE.'" xmlns:com="http://www.sec.gov/edgar/common">'.
            '<headerData><submissionType>'.$form.'</submissionType><filerInfo><liveTestFlag>LIVE</liveTestFlag></filerInfo></headerData><formData>'.
            '<generalInfo reportEndingPeriod="'.$period.'" isReportPeriodLt12="N"/><registrantInfo><registrantFullName>Synthetic trust</registrantFullName>'.
            '<registrantCik>'.str_pad((string) $cik, 10, '0', STR_PAD_LEFT).'</registrantCik></registrantInfo><managementInvestmentQuestionSeriesInfo>'.$funds.
            '</managementInvestmentQuestionSeriesInfo></formData></edgarSubmission>';
    }

    public static function series(array $types = ['Exchange-Traded Fund'], array $rules = ['Rule 6c-11 (17 CFR 270.6c-11)'], string $id = 'S000000001', array $classes = [['C000000001', 'DEMO']]): array
    {
        return ['id' => $id, 'classes' => $classes, 'types' => $types, 'index' => true, 'rules' => $rules];
    }

    /** One plain index ETF series in its latest annual report. */
    public static function plainFund(array $series = []): array
    {
        return self::mappings() + [
            self::submissionsUrl(self::FUND_CIK) => Http::response(self::submissions(self::FUND_CIK, 'investment', [], [['0001000001-26-000100', 'N-CEN', '2026-05-31']])),
            self::documentUrl(self::FUND_CIK, '0001000001-26-000100') => Http::response(self::ncen(self::FUND_CIK, '2026-05-31', $series ?: [self::series()])),
        ];
    }
}
