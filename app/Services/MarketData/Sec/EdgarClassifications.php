<?php

declare(strict_types=1);

namespace App\Services\MarketData\Sec;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Public SEC EDGAR reads only: fixed GET destinations, no account, order, credential or brokerage method.
 * Reports regulatory facts; the paper policy decides whether they establish eligibility.
 */
final class EdgarClassifications
{
    public const FUND_TICKERS = 'https://www.sec.gov/files/company_tickers_mf.json';

    public const COMPANY_TICKERS = 'https://www.sec.gov/files/company_tickers.json';

    public const SUBMISSIONS = 'https://data.sec.gov/submissions/';

    public const ARCHIVES = 'https://www.sec.gov/Archives/edgar/data/';

    public const CONTRACT = 'sec_ncen_item_c3c_rule_18f4_or_sole_operating_ticker_v1';

    public const NAMESPACE = 'http://www.sec.gov/edgar/ncen';

    // Reviewed Form N-CEN 3.1 schema (eis_NCEN_Filer.xsd FUND_TYPE plus fixed index/master-feeder attributes).
    public const FUND_TYPES = ['Exchange-Traded Fund', 'Exchange-Traded Managed Fund', 'Inverse of a benchmark', 'Interval Fund',
        'Fund of Funds', 'Money Market Fund', 'Target Date Fund', 'Underlying fund', 'Index Fund', 'Master-Feeder Fund'];

    // Item C.3.c: a multiple of, the inverse of, or a multiple of the inverse of an index or other benchmark.
    public const MULTIPLE_OR_INVERSE = 'Inverse of a benchmark';

    // Item C.7.n.ii: a leveraged/inverse fund excepted by rule 18f-4(c)(5) from the 18f-4(c)(2) leverage-risk limit.
    public const LEVERAGED_INVERSE_RULE = 'Rule 18f-4(c)(2) (17 CFR 270.18f-4(c)(2))';

    // Exact RELY_ON_RULE_TYPE values, including the schema's own spacing.
    public const RULES = ['Rule 10f-3 (17 CFR 270.10f-3)', 'Rule 12d1-1 (17 CFR 270.12d1-1)', 'Rule 15a-4 (17 CFR 270.15a-4)',
        'Rule 17a-6 (17 CFR 270.17a-6)', 'Rule 17a-7 (17 CFR 270.17a-7)', 'Rule 17a-8 (17 CFR 270.17a-8)', 'Rule 17e-1 (17 CFR 270.17e-1)',
        'Rule 22d-1 (17 CFR 270.22d-1)', 'Rule 23c-1 (17 CFR 270.23c-1)', 'Rule 32a-4 (17 CFR 270.32a-4)', 'Rule 6c-11 (17 CFR 270.6c-11)',
        'Rule 12d1-4 (17 CFR 270.12d1-4)', 'Section 12(d)(1)(G) of the Act (15 USC 80a-12(d)(1)(G))', 'Rule 18f-4 (17 CFR 270.18f-4)',
        'Rule 18f-4(c)(4) (17CFR 270.18f-4(c)(4))', self::LEVERAGED_INVERSE_RULE, 'Rule 18f-4(d)(i) (17 CFR 270.18f-4(d)(i))',
        'Rule 18f-4(d)(ii) (17 CFR 270.18f-4(d)(ii))', 'Rule 18f-4(e) (17 CFR 270.18f-4(e))', 'Rule 18f-4(f)(17 CFR 270.18f-4(f))'];

    private const DATE = '/^\d{4}-\d{2}-\d{2}$/D';

    private ?array $funds = null;

    private ?array $companies = null;

    private int $requests = 0;

    /** One batch shares the two public ticker-mapping downloads; nothing is cached across batches. */
    public function readMany(array $symbols, CarbonImmutable $asOf): array
    {
        $this->funds = $this->companies = null;
        $results = [];
        foreach (array_values(array_unique($symbols)) as $symbol) {
            $results[$symbol] = $this->read($symbol, $asOf);
        }
        $this->funds = $this->companies = null;

        return $results;
    }

    public function read(string $symbol, CarbonImmutable $asOf): array
    {
        $result = ['provider' => 'sec_edgar', 'contract' => self::CONTRACT, 'symbol' => $symbol, 'source_as_of' => null,
            'retrieved_at' => null, 'mappings_retrieved_at' => null, 'complete' => false, 'error' => null, 'basis' => null, 'reason' => null,
            'registrant' => null, 'fund' => null, 'multiple_or_inverse' => null, 'requests' => 0];
        if (! config('sec_edgar.enabled')) {
            return array_replace($result, ['error' => 'disabled']);
        }
        $agent = config('sec_edgar.user_agent');
        if (! is_string($agent) || ! preg_match('/^[\x20-\x7E]{3,200}$/D', $agent) || ! str_contains($agent, '@')) {
            return array_replace($result, ['error' => 'declared_user_agent_missing']);
        }
        if (! preg_match('/^[A-Z][A-Z0-9.-]{0,15}$/D', $symbol)) {
            return array_replace($result, ['error' => 'invalid_symbol']);
        }
        $start = $this->requests;
        try {
            [$funds, $companies, $mapped] = $this->mappings();
            $result['mappings_retrieved_at'] = $mapped;
            $fundRows = $funds[$symbol] ?? [];
            $companyRows = $companies['tickers'][$symbol] ?? [];
            if (in_array(null, $fundRows, true) || ($fundRows === [] && in_array(null, $companyRows, true))) {
                throw new EdgarReadFailure('invalid_or_unreviewed_response');
            }
            if (count($fundRows) > 1 || ($fundRows === [] && count($companyRows) > 1)) {
                $outcome = ['basis' => 'unclassified', 'reason' => 'ambiguous_ticker_mapping'];
            } elseif ($fundRows !== []) {
                $outcome = $this->fund($symbol, $fundRows[0], $asOf);
            } elseif ($companyRows !== []) {
                $outcome = $this->issuer($symbol, $companyRows[0], $companies['per_cik'][$companyRows[0]] ?? 0);
            } else {
                $outcome = ['basis' => 'unclassified', 'reason' => 'ticker_not_in_sec_mappings'];
            }
            $result = array_replace($result, $outcome, ['complete' => true]);
        } catch (EdgarReadFailure $failure) {
            $result['error'] = $failure->reason;
        } catch (ConnectionException) {
            $result['error'] = 'transport_unavailable';
        } catch (\JsonException|\TypeError|\ValueError) {
            $result['error'] = 'invalid_or_unreviewed_response';
        } catch (\Throwable) {
            $result['error'] = 'transport_or_response_failure'; // Never persist provider bodies or request exceptions.
        }
        $result['requests'] = $this->requests - $start;
        $result['retrieved_at'] = CarbonImmutable::now()->toISOString();

        return $result;
    }

    private function fund(string $symbol, array $row, CarbonImmutable $asOf): array
    {
        [$cik, $series, $class] = $row;
        $submissions = $this->submissions($cik);
        $registrant = ['cik' => $submissions['cik'], 'entity_type' => $submissions['entity_type'], 'listed_ticker_count' => count($submissions['tickers'])];
        if ($submissions['entity_type'] !== 'investment') {
            return ['basis' => 'unclassified', 'reason' => 'registrant_type_mismatch', 'registrant' => $registrant];
        }
        $floor = $asOf->subDays((int) config('sec_edgar.report_max_age_days'))->toDateString();
        ['oldest' => $oldest, 'ncen' => $filings] = $submissions['filings'];
        $pages = 0;
        // A report inside the age window was filed inside it too; read older pages only until that window is covered.
        foreach ($submissions['older_pages'] as $page) {
            if ($oldest !== null && $oldest <= $floor) {
                break;
            }
            if ($page['filing_to'] < $floor) {
                continue;
            }
            if (++$pages > (int) config('sec_edgar.max_history_pages')) {
                throw new EdgarReadFailure('history_page_limit');
            }
            $older = $this->filings($this->json($this->get(self::SUBMISSIONS.$page['name'], (int) config('sec_edgar.max_mapping_bytes'))));
            $oldest = $older['oldest'] === null ? $oldest : min($oldest ?? $older['oldest'], $older['oldest']);
            $filings = [...$filings, ...$older['ncen']];
        }
        $candidates = array_values(array_filter($filings, fn ($f) => $f['report_date'] >= $floor));
        usort($candidates, fn ($a, $b) => strcmp($b['report_date'], $a['report_date']) ?: strcmp($b['accepted_at'], $a['accepted_at']));
        $examined = 0;
        foreach ($candidates as $filing) {
            if (++$examined > (int) config('sec_edgar.max_ncen_documents')) {
                throw new EdgarReadFailure('filing_limit');
            }
            if ($filing['size'] > (int) config('sec_edgar.max_document_bytes')) {
                throw new EdgarReadFailure('response_limit');
            }
            $url = self::ARCHIVES.(int) $cik.'/'.str_replace('-', '', $filing['accession']).'/primary_doc.xml';
            $report = $this->ncen($this->get($url, (int) config('sec_edgar.max_document_bytes')), $series);
            if ($report['form'] !== $filing['form'] || $report['registrant_cik'] !== (int) $cik || $report['report_period_end'] !== $filing['report_date']) {
                throw new EdgarReadFailure('invalid_or_unreviewed_response');
            }
            if ($report['series'] === null) {
                continue;
            }
            $found = $report['series'];
            $fund = ['series_id' => $series, 'class_id' => $class, 'accession' => $filing['accession'], 'form' => $filing['form'],
                'filing_date' => $filing['filing_date'], 'accepted_at' => $filing['accepted_at'], 'report_period_end' => $filing['report_date'],
                'fund_types' => $found['fund_types'], 'rely_on_rules' => $found['rules'], 'filings_examined' => $examined];
            $matches = array_filter($found['classes'], fn ($c) => $c['class_id'] === $class && $c['ticker'] === $symbol);
            if (count($matches) !== 1) {
                return ['basis' => 'unclassified', 'reason' => 'class_ticker_not_confirmed_in_ncen', 'registrant' => $registrant, 'fund' => $fund];
            }
            if (array_diff($found['fund_types'], self::FUND_TYPES) !== [] || array_diff($found['rules'], self::RULES) !== []) {
                return ['basis' => 'unclassified', 'reason' => 'unreviewed_ncen_value', 'registrant' => $registrant, 'fund' => $fund];
            }

            return ['basis' => 'registered_fund_ncen', 'reason' => null, 'registrant' => $registrant, 'fund' => $fund, 'source_as_of' => $filing['accepted_at'],
                'multiple_or_inverse' => in_array(self::MULTIPLE_OR_INVERSE, $found['fund_types'], true) || in_array(self::LEVERAGED_INVERSE_RULE, $found['rules'], true)];
        }

        return ['basis' => 'unclassified', 'reason' => 'series_not_in_recent_ncen', 'registrant' => $registrant];
    }

    /** SEC ticker associations attach bank ETN shelves to operating issuers, so only a sole listed ticker qualifies. */
    private function issuer(string $symbol, int $cik, int $mappedTickers): array
    {
        $submissions = $this->submissions($cik);
        $registrant = ['cik' => $submissions['cik'], 'entity_type' => $submissions['entity_type'], 'listed_ticker_count' => count($submissions['tickers'])];
        if ($submissions['entity_type'] !== 'operating') {
            return ['basis' => 'unclassified', 'reason' => 'registrant_not_operating_company', 'registrant' => $registrant];
        }
        if ($submissions['tickers'] !== [$symbol] || $mappedTickers !== 1) {
            return ['basis' => 'unclassified', 'reason' => in_array($symbol, $submissions['tickers'], true) ? 'issuer_has_multiple_listed_securities' : 'ticker_not_in_registrant_listing',
                'registrant' => $registrant];
        }

        return ['basis' => 'operating_issuer_sole_listed_security', 'reason' => null, 'registrant' => $registrant];
    }

    private function mappings(): array
    {
        if ($this->funds === null) {
            $limit = (int) config('sec_edgar.max_mapping_bytes');
            $fund = $this->json($this->get(self::FUND_TICKERS, $limit));
            if (($fund['fields'] ?? null) !== ['cik', 'seriesId', 'classId', 'symbol'] || ! is_array($fund['data'] ?? null) || ! array_is_list($fund['data'])) {
                throw new EdgarReadFailure('invalid_or_unreviewed_response');
            }
            $funds = [];
            foreach ($fund['data'] as $row) {
                if (! is_array($row) || ! array_is_list($row) || count($row) !== 4 || ! is_string($row[3])) {
                    continue; // Rows without a symbol are not evidence for any requested symbol.
                }
                // A malformed row makes only its own symbol's mapping unusable.
                $valid = is_int($row[0]) && $row[0] > 0 && is_string($row[1]) && preg_match('/^S\d{9}$/D', $row[1]) && is_string($row[2]) && preg_match('/^C\d{9}$/D', $row[2]);
                $funds[$row[3]][] = $valid ? [$row[0], $row[1], $row[2]] : null;
            }
            $company = $this->json($this->get(self::COMPANY_TICKERS, $limit));
            $companies = ['tickers' => [], 'per_cik' => []];
            foreach ($company as $row) {
                if (! is_array($row) || ! is_string($row['ticker'] ?? null)) {
                    continue;
                }
                $valid = is_int($row['cik_str'] ?? null) && $row['cik_str'] > 0;
                $companies['tickers'][$row['ticker']][] = $valid ? $row['cik_str'] : null;
                if ($valid) {
                    $companies['per_cik'][$row['cik_str']] = ($companies['per_cik'][$row['cik_str']] ?? 0) + 1;
                }
            }
            $this->funds = $funds;
            $this->companies = $companies + ['retrieved_at' => CarbonImmutable::now()->toISOString()];
        }

        return [$this->funds, $this->companies, $this->companies['retrieved_at']];
    }

    private function submissions(int $cik): array
    {
        $padded = str_pad((string) $cik, 10, '0', STR_PAD_LEFT);
        $data = $this->json($this->get(self::SUBMISSIONS.'CIK'.$padded.'.json', (int) config('sec_edgar.max_mapping_bytes')));
        if (! is_string($data['cik'] ?? null) || (int) $data['cik'] !== $cik || ! is_string($data['entityType'] ?? null) ||
            ! is_array($data['tickers'] ?? null) || ! array_is_list($data['tickers']) || array_filter($data['tickers'], fn ($t) => ! is_string($t)) !== [] ||
            ! is_array($data['filings']['recent'] ?? null) || ! is_array($data['filings']['files'] ?? null)) {
            throw new EdgarReadFailure('invalid_or_unreviewed_response');
        }
        $pages = [];
        foreach ($data['filings']['files'] as $file) {
            if (! is_string($file['name'] ?? null) || ! preg_match('/^CIK'.$padded.'-submissions-\d{3}\.json$/D', $file['name']) ||
                ! is_string($file['filingTo'] ?? null) || ! preg_match(self::DATE, $file['filingTo'])) {
                throw new EdgarReadFailure('invalid_or_unreviewed_response');
            }
            $pages[] = ['name' => $file['name'], 'filing_to' => $file['filingTo']];
        }
        usort($pages, fn ($a, $b) => strcmp($b['filing_to'], $a['filing_to']));

        return ['cik' => $padded, 'entity_type' => $data['entityType'], 'tickers' => $data['tickers'],
            'filings' => $this->filings($data['filings']['recent']), 'older_pages' => $pages];
    }

    private function filings(array $columns): array
    {
        $keys = ['accessionNumber', 'filingDate', 'reportDate', 'acceptanceDateTime', 'form', 'primaryDocument', 'size'];
        foreach ($keys as $key) {
            if (! is_array($columns[$key] ?? null) || ! array_is_list($columns[$key]) || count($columns[$key]) !== count($columns['accessionNumber'] ?? [])) {
                throw new EdgarReadFailure('invalid_or_unreviewed_response');
            }
        }
        $filings = [];
        $oldest = null;
        foreach ($columns['form'] as $i => $form) {
            if (! is_string($form) || ! is_string($columns['filingDate'][$i]) || ! preg_match(self::DATE, $columns['filingDate'][$i])) {
                throw new EdgarReadFailure('invalid_or_unreviewed_response');
            }
            $oldest = min($oldest ?? $columns['filingDate'][$i], $columns['filingDate'][$i]);
            if (! in_array($form, ['N-CEN', 'N-CEN/A'], true)) {
                continue;
            }
            $accession = $columns['accessionNumber'][$i];
            $accepted = $columns['acceptanceDateTime'][$i];
            if (! is_string($accession) || ! preg_match('/^\d{10}-\d{2}-\d{6}$/D', $accession) ||
                ! is_string($columns['reportDate'][$i]) || ! preg_match(self::DATE, $columns['reportDate'][$i]) ||
                ! is_string($accepted) || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?Z$/D', $accepted) ||
                ! is_string($columns['primaryDocument'][$i]) || basename($columns['primaryDocument'][$i]) !== 'primary_doc.xml' || ! is_int($columns['size'][$i])) {
                throw new EdgarReadFailure('invalid_or_unreviewed_response');
            }
            $filings[] = ['accession' => $accession, 'form' => $form, 'filing_date' => $columns['filingDate'][$i], 'report_date' => $columns['reportDate'][$i],
                'accepted_at' => CarbonImmutable::parse($accepted)->toISOString(), 'size' => $columns['size'][$i]];
        }

        return ['oldest' => $oldest, 'ncen' => $filings];
    }

    /** Streams one series at a time; other series are discarded without retaining their prose or service providers. */
    private function ncen(string $xml, string $series): array
    {
        if (str_contains($xml, '<!DOCTYPE') || str_contains($xml, '<!ENTITY')) {
            throw new EdgarReadFailure('invalid_or_unreviewed_response');
        }
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $reader = new \XMLReader;
        $out = ['form' => null, 'registrant_cik' => null, 'report_period_end' => null, 'series' => null];
        try {
            if (! $reader->XML($xml, null, LIBXML_NONET | LIBXML_COMPACT)) {
                throw new EdgarReadFailure('invalid_or_unreviewed_response');
            }
            $root = null;
            $ok = $reader->read();
            while ($ok) {
                if ($reader->nodeType !== \XMLReader::ELEMENT) {
                    $ok = $reader->read();

                    continue;
                }
                if ($root === null) {
                    $root = $reader->namespaceURI.'|'.$reader->localName;
                    if ($root !== self::NAMESPACE.'|edgarSubmission') {
                        throw new EdgarReadFailure('invalid_or_unreviewed_response');
                    }
                }
                if ($reader->localName === 'managementInvestmentQuestion') {
                    $doc = new \DOMDocument;
                    $node = $reader->expand($doc);
                    if (! $node) {
                        throw new EdgarReadFailure('invalid_or_unreviewed_response');
                    }
                    $doc->appendChild($node);
                    $parsed = $this->series($doc);
                    if ($parsed['series_id'] === $series) {
                        if ($out['series'] !== null) {
                            throw new EdgarReadFailure('invalid_or_unreviewed_response');
                        }
                        $out['series'] = $parsed;
                    }
                    $ok = $reader->next();

                    continue;
                }
                $string = in_array($reader->localName, ['submissionType', 'registrantCik'], true) ? trim($reader->readString()) : '';
                $value = match ($reader->localName) {
                    'submissionType' => ['form', $string],
                    'registrantCik' => ['registrant_cik', preg_match('/^\d{1,10}$/D', $string) ? (int) $string : -1],
                    'generalInfo' => ['report_period_end', (string) $reader->getAttribute('reportEndingPeriod')],
                    default => null,
                };
                if ($value !== null) {
                    if ($out[$value[0]] !== null && $out[$value[0]] !== $value[1]) {
                        throw new EdgarReadFailure('invalid_or_unreviewed_response');
                    }
                    $out[$value[0]] = $value[1];
                }
                $ok = $reader->read();
            }
            if (libxml_get_errors() !== [] || $root === null || ! is_string($out['report_period_end']) || ! preg_match(self::DATE, $out['report_period_end'])) {
                throw new EdgarReadFailure('invalid_or_unreviewed_response');
            }
        } catch (\ErrorException) {
            throw new EdgarReadFailure('invalid_or_unreviewed_response'); // Malformed XML surfaces as a converted libxml warning.
        } finally {
            $reader->close();
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return $out;
    }

    private function series(\DOMDocument $doc): array
    {
        $xp = new \DOMXPath($doc);
        $text = fn (\DOMNode $n) => preg_replace('/\s+/', ' ', trim($n->textContent));
        $q = fn (string $path) => iterator_to_array($xp->query('/*[local-name()="managementInvestmentQuestion"]'.$path));
        $ids = $q('/*[local-name()="mgmtInvSeriesId"]');
        $classes = [];
        foreach ($q('/*[local-name()="sharesOutstandings"]/*[local-name()="sharesOutstanding"]') as $class) {
            $classes[] = ['class_id' => $class->getAttribute('sharesOutstandingClassId'), 'ticker' => $class->getAttribute('sharesOutstandingTickerSymbol')];
        }
        $listed = $q('/*[local-name()="fundTypes"]');
        $direct = $q('/*[local-name()="fundType"]');
        if (count($listed) === 1 && $direct === []) {
            $types = array_map($text, $q('/*[local-name()="fundTypes"]/*[local-name()="fundType"]'));
            foreach ($q('/*[local-name()="fundTypes"]/*[local-name()="indexFundInfo" or local-name()="masterFeederFundInfo"]') as $info) {
                $types[] = $info->getAttribute('fundType');
            }
        } elseif ($listed === [] && count($direct) === 1 && $text($direct[0]) === 'N/A') {
            $types = [];
        } else {
            throw new EdgarReadFailure('invalid_or_unreviewed_response');
        }
        $ruleList = $q('/*[local-name()="relyOnRuleTypes"]');
        $ruleDirect = $q('/*[local-name()="relyOnRuleType"]');
        if (count($ruleList) === 1 && $ruleDirect === []) {
            $rules = array_map($text, $q('/*[local-name()="relyOnRuleTypes"]/*[local-name()="relyOnRuleType"]'));
        } elseif ($ruleList === [] && count($ruleDirect) === 1 && $text($ruleDirect[0]) === 'N/A') {
            $rules = [];
        } else {
            throw new EdgarReadFailure('invalid_or_unreviewed_response');
        }

        return ['series_id' => count($ids) === 1 ? $text($ids[0]) : null, 'classes' => $classes,
            'fund_types' => array_values(array_unique($types)), 'rules' => array_values(array_unique($rules))];
    }

    private function json(string $body): array
    {
        $data = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        if (! is_array($data)) {
            throw new EdgarReadFailure('invalid_or_unreviewed_response');
        }

        return $data;
    }

    private function get(string $url, int $limit): string
    {
        if ($this->requests > 0 && ($interval = (int) config('sec_edgar.request_interval_ms')) > 0) {
            usleep($interval * 1000); // SEC fair access: stay well below ten requests per second.
        }
        $this->requests++;
        $exceeded = false;
        try {
            $response = Http::withHeaders(['User-Agent' => config('sec_edgar.user_agent'), 'Accept-Encoding' => 'gzip, deflate'])
                ->timeout(30)->connectTimeout(5)->withOptions(['allow_redirects' => false, 'progress' => function ($total, $downloaded) use ($limit, &$exceeded): void {
                    if ($downloaded > $limit) {
                        $exceeded = true;
                        throw new \RuntimeException('Response limit.');
                    }
                }])->get($url);
        } catch (\Throwable $e) {
            if ($exceeded) {
                throw new EdgarReadFailure('response_limit');
            }
            throw $e;
        }
        if (! $response->successful()) {
            throw new EdgarReadFailure(match ($response->status()) {
                403 => 'access_denied', 404 => 'not_found', 429 => 'rate_limited', default => 'http_failure',
            });
        }
        $body = $response->body();
        if (strlen($body) > $limit) {
            throw new EdgarReadFailure('response_limit');
        }

        return $body;
    }
}
