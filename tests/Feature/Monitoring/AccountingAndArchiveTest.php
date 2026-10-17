<?php

namespace Tests\Feature\Monitoring;

use App\Models\Monitoring\ArchiveImport;
use App\Models\Monitoring\ArchiveRow;
use App\Models\Monitoring\MarketSession;
use App\Models\Monitoring\PolicyVersion;
use App\Models\Monitoring\Run;
use App\Services\Monitoring\ArchiveImporter;
use App\Services\Monitoring\FlowAccounting;
use App\Services\Monitoring\InvalidationEvaluator;
use App\Services\Monitoring\MarketCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\McpFixture;
use Tests\TestCase;

class AccountingAndArchiveTest extends TestCase
{
    use RefreshDatabase;

    public function test_deposit_does_not_become_gain_and_fees_and_distributions_remain_performance(): void
    {
        $accounting = new FlowAccounting;
        $baseline = ['equity' => '100', 'units' => '100', 'as_of' => '2026-10-15T14:00:00Z', 'source_ref' => 'synthetic-baseline'];
        $event = ['source_ref' => 'synthetic-flow', 'occurred_at' => '2026-10-16T14:00:00Z', 'valuation_as_of' => '2026-10-16T14:00:00Z',
            'equity_before' => '100', 'amount' => '100', 'classification' => 'deposit'];
        $r = $accounting->calculate($baseline, [$event], '200', true);
        $this->assertTrue($r['available']);
        $this->assertSame('0.000000000000000000', $r['return']);
        $this->assertSame('200.000000000000000000', $r['units']);
        $event['classification'] = 'fee';
        $event['amount'] = '-1';
        $this->assertSame('-0.010000000000000000', $accounting->calculate($baseline, [$event], '99', true)['return']);
        $event['classification'] = 'distribution';
        $event['amount'] = '1';
        $this->assertSame('0.010000000000000000', $accounting->calculate($baseline, [$event], '101', true)['return']);
        $this->assertFalse($accounting->calculate($baseline, [], '100', false)['available']);
        $event['classification'] = 'unknown';
        $this->assertSame('unclassified_flow', $accounting->calculate($baseline, [$event], '101', true)['reason']);
        $event['valuation_as_of'] = '2026-10-16T13:00:00Z';
        $this->assertFalse($accounting->calculate($baseline, [$event], '101', true)['available']);
        $this->assertFalse($accounting->calculate(null, [], '101', true)['available']);
    }

    public function test_completed_close_rule_never_uses_intraday_quote_and_needs_verified_session(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-16T20:01:00Z'));
        $calendar = app(MarketCalendar::class);
        $calendar->store('2026-10-16', 'regular', '2026-10-16T13:30:00Z', '2026-10-16T20:00:00Z', 'fake:verified-fixture');
        $rule = ['type' => 'completed_session_close', 'operator' => 'lte', 'threshold' => '100'];
        $evidence = ['data' => ['last' => '90', 'close' => '90'], 'source_as_of' => now()->toIso8601String()];
        $evaluator = app(InvalidationEvaluator::class);
        $this->assertSame('unavailable', $evaluator->evaluate($rule, $evidence, now()->toIso8601String()));
        $evidence += ['kind' => 'completed_daily_bar', 'complete' => true, 'session_date' => '2026-10-16'];
        $this->assertSame('breached', $evaluator->evaluate($rule, $evidence, now()->toIso8601String()));
        $intraday = $evidence;
        $intraday['source_as_of'] = '2026-10-16T15:00:00Z';
        $this->travelTo(CarbonImmutable::parse('2026-10-16T15:01:00Z'));
        $this->assertSame('unavailable', $evaluator->evaluate($rule, $intraday, now()->toIso8601String()));
        $this->travelTo(CarbonImmutable::parse('2026-10-16T20:01:00Z'));
        MarketSession::query()->delete();
        $this->assertSame('unavailable', $evaluator->evaluate($rule, $evidence, now()->toIso8601String()));
        $rule['type'] = 'price';
        $this->assertSame('breached', $evaluator->evaluate($rule, $evidence, now()->toIso8601String()));
        $this->travel(6)->minutes();
        $this->assertSame('unavailable', $evaluator->evaluate($rule, $evidence, now()->toIso8601String()));
        $this->assertSame('review_required', $evaluator->evaluate(['type' => 'fundamental_event'], null, null));
    }

    public function test_calendar_handles_holiday_early_close_and_dst_without_inference(): void
    {
        $calendar = app(MarketCalendar::class);
        $this->travelTo(CarbonImmutable::parse('2026-11-27T17:59:00Z'));
        $this->assertSame('unknown', $calendar->at(CarbonImmutable::now())['status']);
        $calendar->store('2026-11-26', 'holiday', null, null, 'fake:holiday');
        $calendar->store('2026-11-27', 'early_close', '2026-11-27T14:30:00Z', '2026-11-27T18:00:00Z', 'fake:early-close');
        $this->assertSame('holiday', $calendar->at(CarbonImmutable::parse('2026-11-26T16:00:00Z'))['status']);
        $this->assertSame('regular', $calendar->at(CarbonImmutable::now())['status']);
        $this->assertSame('after_close', $calendar->at(CarbonImmutable::parse('2026-11-27T18:00:00Z'))['status']);
        $calendar->store('2026-11-02', 'regular', '2026-11-02T14:30:00Z', '2026-11-02T21:00:00Z', 'fake:standard-time');
        $this->assertSame('before_open', $calendar->at(CarbonImmutable::parse('2026-11-02T14:29:00Z'))['status']);
        $calendar->store('2026-10-30', 'regular', '2026-10-30T13:30:00Z', '2026-10-30T20:00:00Z', 'fake:daylight-time');
        $this->assertSame('regular', $calendar->at(CarbonImmutable::parse('2026-10-30T13:30:00Z'))['status']);
    }

    public function test_archive_preview_is_read_only_commit_is_idempotent_and_quarantines_bad_rows(): void
    {
        $connection = McpFixture::connection();
        $bytes = json_encode([
            ['sheet' => 'Bot Control', 'id' => 'original-control', 'live_enabled' => true],
            ['sheet' => 'Check Log', 'id' => 'undefined'],
            ['sheet' => 'Trade Ledger', 'id' => 'bad-fill', 'quantity' => '1', 'filled_quantity' => '2'],
            ['sheet' => 'Performance Log', 'id' => 'unknown-flow'],
            ['sheet' => 'Bot Control', 'id' => 'original-control'],
            ['sheet' => 'Check Log', 'id' => 'original-run'],
        ]);
        $importer = app(ArchiveImporter::class);
        $preview = $importer->preview($bytes);
        $this->assertSame(3, $preview['quarantined']);
        $this->assertSame(1, $preview['duplicates']);
        $this->assertSame(0, ArchiveImport::count());
        $this->assertFalse($preview['operational_import']);
        $first = $importer->commit($connection, $connection->user_id, $bytes);
        $this->assertSame($first->id, $importer->commit($connection, $connection->user_id, $bytes)->id);
        $this->assertSame(6, ArchiveRow::count());
        $this->assertSame(0, Run::count());
        $this->assertSame(0, PolicyVersion::count());
        $this->assertSame(hash('sha256', $bytes), $first->source_hash);
        $otherExport = json_encode([['sheet' => 'Bot Control', 'id' => 'original-control']]);
        $second = $importer->commit($connection, $connection->user_id, $otherExport);
        $this->assertSame(1, $second->report['duplicates']);
        $this->assertSame('duplicate_existing_source_reference', ArchiveRow::where('import_id', $second->id)->first()->reason);
        $this->expectException(\InvalidArgumentException::class);
        $importer->commit($connection, $connection->user_id + 1, $bytes);
    }

    public function test_bad_archive_decimal_is_quarantined_instead_of_becoming_a_float(): void
    {
        $report = app(ArchiveImporter::class)->preview('[{"sheet":"Trade Ledger","id":"bad","quantity":1.2,"filled_quantity":"0"}]');
        $this->assertSame('invalid_decimal', $report['rows'][0]['reason']);
    }
}
