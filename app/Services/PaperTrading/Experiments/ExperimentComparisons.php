<?php

declare(strict_types=1);

namespace App\Services\PaperTrading\Experiments;

use App\Support\Mcp\Decimal;

final class ExperimentComparisons
{
    public function summarize(array $result, array $verification): array
    {
        $playbooks = [];
        foreach (['breakout', 'pullback'] as $setup) {
            $trades = array_values(array_filter($result['trades'], fn ($t) => $t['setup'] === $setup));
            $net = Decimal::from('0');
            $wins = 0;
            foreach ($trades as $trade) {
                $value = Decimal::from($trade['net_exact']);
                $net = $net->plus($value);
                $wins += $value->compareTo(Decimal::from('0')) > 0 ? 1 : 0;
            }
            $playbooks[$setup] = ['closed_trades' => count($trades), 'wins' => $wins, 'closed_trade_net' => (string) $net,
                'open_positions' => count(array_filter($result['open_positions'], fn ($p) => $p['setup'] === $setup))];
        }
        $fees = Decimal::from('0');
        foreach ($result['fills'] as $fill) {
            $fees = $fees->plus(Decimal::from($fill['fee']));
        }

        return ['initial_equity' => $result['initial_equity'], 'final_equity' => $result['final_equity'], 'cash' => $result['cash'],
            'closed_trade_net' => $result['closed_trade_net'], 'open_episode_marked_pnl' => $result['open_episode_marked_pnl'],
            'orders' => count($result['orders']), 'fills' => count($result['fills']), 'open_positions' => count($result['open_positions']),
            'fees' => (string) $fees, 'execution' => $result['execution'] ?? null, 'stats' => $result['stats'], 'coverage' => $result['coverage'], 'playbooks' => $playbooks,
            'event_reasons' => array_count_values(array_column($result['events'], 'reason')), 'verification' => $verification,
            'assessment' => $result['trades'] === [] ? 'no_closed_trades_inconclusive' : 'exploratory_only_no_edge_claim', 'limitations' => $result['limitations']];
    }

    public function build(array $definition, array $sessions, array $variants, string $datasetHash): array
    {
        $baseline = $definition['spec']['baseline'];
        $base = $variants[$baseline]['metrics'];
        $comparisons = [];
        foreach ($variants as $id => $variant) {
            if ($variant['dataset_hash'] !== $datasetHash) {
                throw new \RuntimeException('Cannot compare different frozen datasets.');
            }
            $m = $variant['metrics'];
            $subtract = fn ($a, $b) => $a === null || $b === null ? null : (string) Decimal::from($a)->minus(Decimal::from($b));
            $comparisons[$id] = ['versus' => $baseline, 'return_difference_percentage_points' => $subtract($m['stats']['total_return_pct'], $base['stats']['total_return_pct']),
                'drawdown_difference_percentage_points' => $subtract($m['stats']['max_drawdown_pct'], $base['stats']['max_drawdown_pct']),
                'closed_trades_difference' => $m['stats']['closed_trades'] - $base['stats']['closed_trades'],
                'capital_changed' => Decimal::from($m['initial_equity'])->compareTo(Decimal::from($base['initial_equity'])) !== 0,
                'same_dataset' => $variant['dataset_hash'] === $datasetHash];
        }
        $first = $sessions[0];
        $last = $sessions[array_key_last($sessions)];
        $firstFrame = $first['frames'][0];
        $lastFrame = $last['frames'][array_key_last($last['frames'])];
        $benchmark = [];
        $total = Decimal::from('0');
        $complete = true;
        foreach ($definition['spec']['symbols'] as $symbol) {
            $open = $firstFrame['quotes'][$symbol] ?? null;
            $close = $lastFrame['quotes'][$symbol] ?? null;
            $valid = ReplayAccountingVerifier::usable($open, $firstFrame, $first['session'], $definition['settings']['quote_max_age_seconds']) &&
                ReplayAccountingVerifier::usable($close, $lastFrame, $last['session'], $definition['settings']['quote_max_age_seconds']);
            $change = $valid ? Decimal::from($close['bid'])->minus(Decimal::from($open['ask']))->multipliedBy(Decimal::from('100'))->dividedBy(Decimal::from($open['ask']), 4) : null;
            $benchmark[$symbol] = $change === null ? null : (string) $change;
            $complete = $complete && $change !== null;
            $total = $total->plus($change ?? Decimal::from('0'));
        }

        return ['version' => ExperimentDefinition::VERSION, 'label' => 'retrospective_sampled_quote_scenario_not_forward_or_point_in_time',
            'name' => $definition['spec']['name'], 'hypothesis' => $definition['spec']['hypothesis'], 'evaluation_role' => 'exploratory',
            'dataset_hash' => $datasetHash, 'parameters' => $definition['parameters'] ?? null, 'settings' => $definition['settings'],
            'variant_definitions' => $definition['variants'] ?? null, 'rule_version' => $definition['rule_version'] ?? null, 'profile_version' => $definition['profile_version'] ?? null,
            'baseline' => $baseline, 'variants' => array_map(fn ($v) => $v['metrics'], $variants), 'comparisons' => $comparisons,
            'benchmark' => ['basis' => 'equal_weight_research_universe_first_ask_to_terminal_bid_price_only_no_fees', 'per_symbol_return_pct' => $benchmark,
                'return_pct' => $complete ? (string) $total->dividedBy(Decimal::from((string) count($benchmark)), 4) : null,
                'reason' => $complete ? 'sampled_quote_reference_not_total_return' : 'opening_or_terminal_quote_unavailable', 'spy_return_pct' => null, 'spy_reason' => 'benchmark_quotes_not_acquired'],
            'source_hashes' => $definition['source_hashes'], 'broker_executable' => false, 'forward_ledger_changed' => false,
            'interpretation' => 'Exploratory comparison; no automatic winner selection, policy application or profitability claim.'];
    }

    /** Compare a rebuilt rule version with the declared dataset parent's frozen baseline. */
    public function reference(array $report, array $prior, string $experimentId): array
    {
        if ($report['dataset_hash'] !== $prior['dataset_hash']) {
            throw new \RuntimeException('Reference experiment uses a different dataset.');
        }
        $base = $prior['variants'][$prior['baseline']];
        $rows = [];
        foreach ($report['variants'] as $id => $metrics) {
            $sameSettings = ExperimentDefinition::hash($report['settings']) === ExperimentDefinition::hash($prior['settings']);
            $sameCapital = Decimal::from($metrics['initial_equity'])->compareTo(Decimal::from($base['initial_equity'])) === 0;
            $valid = $sameSettings && $sameCapital;
            $delta = fn ($a, $b) => ! $valid || $a === null || $b === null ? null : (string) Decimal::from($a)->minus(Decimal::from($b));
            $rows[$id] = ['reference_experiment' => $experimentId, 'reference_variant' => $prior['baseline'], 'comparable' => $valid,
                'reason' => $valid ? 'same_frozen_data_capital_and_settings' : 'capital_or_settings_changed',
                'return_difference_percentage_points' => $delta($metrics['stats']['total_return_pct'], $base['stats']['total_return_pct']),
                'drawdown_difference_percentage_points' => $delta($metrics['stats']['max_drawdown_pct'], $base['stats']['max_drawdown_pct'])];
        }

        return ['source_changed' => $report['source_hashes'] !== $prior['source_hashes'], 'baseline_metrics' => $base,
            'baseline_definition' => array_values(array_filter($prior['variant_definitions'], fn ($v) => $v['id'] === $prior['baseline']))[0], 'comparisons' => $rows];
    }
}
