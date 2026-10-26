<?php

namespace Tests\Unit\PaperTrading;

use App\Services\PaperTrading\Experiments\ExperimentComparisons;
use App\Services\PaperTrading\Experiments\ReplayAccountingVerifier;
use App\Services\PaperTrading\SignalParameters;
use App\Services\PaperTrading\StrategyReplay;
use Carbon\CarbonImmutable;
use Tests\Support\IntradayReplayFixture as F;
use Tests\TestCase;

class ReplayAccountingVerifierTest extends TestCase
{
    private function sessions(): array
    {
        $one = F::data();
        $one['window_end'] = $one['session']['closes_at'];
        array_unshift($one['frames'], F::frame('2026-10-19T13:30:00Z'));
        $one['frames'][] = F::frame('2026-10-19T19:59:59Z', 'mark');
        $one['action_reads'] = ['DEMO' => [['complete' => true, 'events' => []]], 'SPY' => [['complete' => true, 'events' => []]]];
        $two = $one;
        $two['date'] = $two['session']['date'] = '2026-10-20';
        $two['previous_session'] = '2026-10-19';
        foreach (['opens_at', 'closes_at'] as $key) {
            $two['session'][$key] = str_replace('19T', '20T', $one['session'][$key]);
        }
        $two['window_end'] = $two['session']['closes_at'];
        foreach ($two['daily_reads'] as &$read) {
            $bar = $read['bars'][array_key_last($read['bars'])];
            $bar[0] = '2026-10-19';
            $read['bars'][] = $bar;
        }
        unset($read);
        $two['frames'] = array_map(function ($f) {
            $at = CarbonImmutable::parse($f['at'])->addDay()->toISOString();

            return F::frame($at, $f['kind']);
        }, $one['frames']);

        return [$one, $two];
    }

    public function test_reconstructs_round_trips_and_rejects_tampered_cash_quantity_fees_timing_and_curve(): void
    {
        $data = $this->sessions();
        $settings = F::settings();
        $r = app(StrategyReplay::class)->runQuoteWindow($data, SignalParameters::current()['values'], $settings, '10000');
        $verifier = app(ReplayAccountingVerifier::class);
        $this->assertSame('passed', $verifier->verify($data, $r, $settings, '10000')['status']);
        $this->assertNotEmpty($r['trades']);
        foreach (['cash', 'size', 'fee', 'source', 'curve', 'net', 'wins'] as $case) {
            $bad = $r;
            match ($case) {
                'cash' => $bad['cash'] = '10000',
                'size' => $bad['fills'][0]['quantity'] = '200',
                'fee' => $bad['fills'][0]['fee'] = '999',
                'source' => $bad['fills'][0]['source_ns'] = '2026-10-19T13:44:59.000000000Z',
                'curve' => $bad['curve'][1][1] = '1',
                'net' => $bad['trades'][0]['net_exact'] = '100',
                'wins' => $bad['stats']['wins'] = 999,
            };
            try {
                $verifier->verify($data, $bad, $settings, '10000');
                $this->fail('Tampered result accepted: '.$case);
            } catch (\RuntimeException $e) {
                $this->assertSame('Replay accounting verification failed.', $e->getMessage());
            }
        }
        $metrics = app(ExperimentComparisons::class)->summarize($r, $verifier->verify($data, $r, $settings, '10000'));
        $this->assertSame(count($r['trades']), $metrics['playbooks']['pullback']['closed_trades'] + $metrics['playbooks']['breakout']['closed_trades']);
    }

    public function test_missing_held_marks_stay_unknown_and_a_benchmark_never_substitutes_missing_opening_quotes(): void
    {
        $data = $this->sessions();
        $data[0]['frames'] = [$data[0]['frames'][0], $data[0]['frames'][1], $data[0]['frames'][2], $data[0]['frames'][5]];
        $data[1]['frames'] = [$data[1]['frames'][0], $data[1]['frames'][1], $data[1]['frames'][2],
            ['at' => '2026-10-20T19:59:59Z', 'kind' => 'mark', 'quotes' => ['DEMO' => null]]];
        $p = SignalParameters::current()['values'];
        $r = app(StrategyReplay::class)->runQuoteWindow($data, $p, F::settings(), '10000');
        $v = app(ReplayAccountingVerifier::class)->verify($data, $r, F::settings(), '10000');
        $this->assertNull($r['final_equity']);
        $this->assertSame('passed', $v['status']);
        $m = app(ExperimentComparisons::class)->summarize($r, $v);
        $definition = ['spec' => ['baseline' => 'base', 'symbols' => ['DEMO'], 'name' => 'Missing quotes', 'hypothesis' => 'No guessed benchmark'],
            'settings' => F::settings(), 'source_hashes' => []];
        $report = app(ExperimentComparisons::class)->build($definition, $data, ['base' => ['metrics' => $m, 'dataset_hash' => 'same']], 'same');
        $this->assertNull($report['benchmark']['return_pct']);
        $this->assertNull($report['comparisons']['base']['return_difference_percentage_points']);
    }

    public function test_fractional_submission_fills_reconcile_and_cannot_disguise_invalid_precision_or_timing(): void
    {
        $data = $this->sessions();
        $settings = array_replace(F::settings(), ['allow_fractional' => true, 'slippage_bps' => '5']);
        $r = app(StrategyReplay::class)->runQuoteWindow($data, SignalParameters::current()['values'], $settings, '500');
        $verifier = app(ReplayAccountingVerifier::class);
        $this->assertNotEmpty($r['trades']);
        $this->assertSame('passed', $verifier->verify($data, $r, $settings, '500')['status']);
        foreach (['precision', 'type', 'time', 'future', 'cash'] as $case) {
            $bad = $r;
            match ($case) {
                'precision' => $bad['fills'][0]['quantity'] .= '1',
                'type' => $bad['orders'][0]['type'] = 'limit',
                'time' => $bad['orders'][0]['accepted_at'] = '2026-10-19T13:50:00Z',
                'future' => $bad['fills'][0]['source_ns'] = '2026-10-19T13:45:00.000000001Z',
                'cash' => $bad['cash'] = '500',
            };
            try {
                $verifier->verify($data, $bad, $settings, '500');
                $this->fail('Tampered fractional result accepted: '.$case);
            } catch (\RuntimeException $e) {
                $this->assertSame('Replay accounting verification failed.', $e->getMessage());
            }
        }
    }
}
