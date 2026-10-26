<?php

declare(strict_types=1);

namespace App\Services\PaperTrading;

use App\Support\Mcp\Decimal;
use Carbon\CarbonImmutable;

/** Consecutive, full-session research using the same quote/rule/sizing step as single-session replay. */
trait MultiSessionQuoteReplay
{
    public function runQuoteSessions(array $sessions, array $parameters, array $settings, string $initial): array
    {
        return $this->replayQuoteSessions($sessions, $parameters, $settings, $initial, 5, self::MULTISESSION_VERSION);
    }

    /** Bounded experiment window, still chronological; never independently restart a session flat. */
    public function runQuoteWindow(array $sessions, array $parameters, array $settings, string $initial): array
    {
        return $this->replayQuoteSessions($sessions, $parameters, $settings, $initial, 20, 'intraday_experiment_v2');
    }

    private function replayQuoteSessions(array $sessions, array $parameters, array $settings, string $initial, int $maximum, string $version): array
    {
        if (! array_is_list($sessions) || count($sessions) < 2 || count($sessions) > $maximum) {
            throw new \InvalidArgumentException('Supply two to '.$maximum.' consecutive completed sessions.');
        }
        $first = $sessions[0]['date'];
        $last = $sessions[array_key_last($sessions)]['date'];
        $symbols = $sessions[0]['symbols'];
        sort($symbols);
        // Validate the whole acquisition before producing any performance; no skipped days or partial windows.
        foreach ($sessions as $index => $data) {
            $members = $data['symbols'];
            sort($members);
            $finalFrame = $data['frames'][array_key_last($data['frames'])] ?? null;
            if (! ($data['complete'] ?? false) || $members !== $symbols ||
                ! CarbonImmutable::parse($data['window_end'])->eq(CarbonImmutable::parse($data['session']['closes_at'])) ||
                ($index > 0 && ($data['date'] <= $sessions[$index - 1]['date'] || $data['previous_session'] !== $sessions[$index - 1]['date'])) ||
                ! isset($data['frames'][0]['at']) || ! CarbonImmutable::parse($data['frames'][0]['at'])->eq(CarbonImmutable::parse($data['session']['opens_at'])) ||
                ($finalFrame['kind'] ?? null) !== 'mark' || ! CarbonImmutable::parse($finalFrame['at'])->eq(CarbonImmutable::parse($data['session']['closes_at'])->subSecond())) {
                throw new \InvalidArgumentException('Incomplete, nonconsecutive or inconsistent multi-session acquisition.');
            }
            foreach (array_unique([...$symbols, $parameters['benchmark_symbol']]) as $symbol) {
                $reads = $data['action_reads'][$symbol] ?? [];
                if ($reads === []) {
                    throw new \InvalidArgumentException('Missing action history.');
                }
                foreach ($reads as $read) {
                    if (! ($read['complete'] ?? false)) {
                        throw new \InvalidArgumentException('Incomplete action history.');
                    }
                    foreach ($read['events'] as $event) {
                        $exDate = $event['dates']['ex_date'] ?? null;
                        if ($event['type'] !== 'cash_dividends' || $exDate === null || ($exDate >= $first && $exDate <= $last)) {
                            throw new \InvalidArgumentException('Overnight action requires entitlement/adjustment support.');
                        }
                    }
                }
            }
        }
        $state = null;
        $reports = [];
        $profiles = [];
        foreach ($sessions as $index => $data) {
            $beforeFills = count($state['fills'] ?? []);
            $beforeTrades = count($state['trades'] ?? []);
            $step = $this->runQuoteSession($data, $parameters, $settings, $initial, $state, $index);
            $result = $step['result'];
            $state = $step['state'];
            $profiles[$data['date']] = $result['profiles'];
            $reports[] = ['date' => $data['date'], 'opening_equity' => $step['opening_equity'], 'daily_loss_paused' => $step['daily_loss_paused'],
                'closing_equity' => $result['final_equity'], 'cash' => $result['cash'], 'fills' => count($result['fills']) - $beforeFills,
                'closed_trades' => count($result['trades']) - $beforeTrades, 'open_positions' => $result['open_positions']];
        }
        $result['version'] = $version;
        $result['initial_equity'] = $initial;
        $result['start_date'] = $first;
        $result['end_date'] = $last;
        $result['sessions'] = $reports;
        $result['profiles_by_session'] = $profiles;
        $realized = Decimal::from('0');
        foreach ($result['trades'] as $trade) {
            $realized = $realized->plus(Decimal::from($trade['net_exact']));
        }
        $result['closed_trade_net'] = (string) $realized;
        $result['open_episode_marked_pnl'] = $result['final_equity'] === null ? null
            : (string) Decimal::from($result['final_equity'])->minus(Decimal::from($initial))->minus($realized);
        $result['limitations'] = array_values(array_diff($result['limitations'], ['single_session_flat_start_no_overnight_actions', 'unfilled_day_orders_remain_pending_at_sample_window_end']));
        $result['limitations'][] = 'observed_action_windows_not_guaranteed_historical_clearance';
        $result['limitations'][] = 'sessions_with_missing_opening_marks_block_all_new_entries';
        $result['limitations'][] = 'flat_start_and_open_terminal_positions_no_forced_liquidation';

        return $result;
    }
}
