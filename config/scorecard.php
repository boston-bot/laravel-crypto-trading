<?php

return [
    'minimum_weighted_score_pct' => (float) env('SCORECARD_MIN_SCORE_PCT', 75.0),

    'weights' => [
        'backtest' => (float) env('SCORECARD_WEIGHT_BACKTEST', 0.40),
        'paper' => (float) env('SCORECARD_WEIGHT_PAPER', 0.45),
        'operations' => (float) env('SCORECARD_WEIGHT_OPERATIONS', 0.15),
    ],

    'backtest' => [
        'lookback_days' => (int) env('SCORECARD_BACKTEST_LOOKBACK_DAYS', 180),
        'min_runs' => (int) env('SCORECARD_BACKTEST_MIN_RUNS', 1),
        'min_sharpe' => (float) env('SCORECARD_BACKTEST_MIN_SHARPE', 1.00),
        'max_drawdown_pct' => (float) env('SCORECARD_BACKTEST_MAX_DRAWDOWN_PCT', 15.0),
        'min_win_rate_pct' => (float) env('SCORECARD_BACKTEST_MIN_WIN_RATE_PCT', 45.0),
        'min_profit_factor' => (float) env('SCORECARD_BACKTEST_MIN_PROFIT_FACTOR', 1.15),
        'metric_aliases' => [
            'sharpe' => ['sharpe', 'sharpe_ratio'],
            'max_drawdown_pct' => ['max_drawdown_pct', 'max_drawdown', 'drawdown_pct', 'max_dd_pct'],
            'win_rate_pct' => ['win_rate_pct', 'win_rate', 'hit_rate_pct', 'hit_rate'],
            'profit_factor' => ['profit_factor', 'pf'],
        ],
    ],

    'paper' => [
        'min_days' => (int) env('SCORECARD_PAPER_MIN_DAYS', 21),
        'min_snapshots' => (int) env('SCORECARD_PAPER_MIN_SNAPSHOTS', 30),
        'min_trades' => (int) env('SCORECARD_PAPER_MIN_TRADES', 15),
        'min_sharpe' => (float) env('SCORECARD_PAPER_MIN_SHARPE', 0.75),
        'max_drawdown_pct' => (float) env('SCORECARD_PAPER_MAX_DRAWDOWN_PCT', 10.0),
        'min_hit_rate_pct' => (float) env('SCORECARD_PAPER_MIN_HIT_RATE_PCT', 45.0),
        'min_profit_factor' => (float) env('SCORECARD_PAPER_MIN_PROFIT_FACTOR', 1.05),
        'max_avg_slippage_bps' => (float) env('SCORECARD_PAPER_MAX_AVG_SLIPPAGE_BPS', 75.0),
    ],

    'operations' => [
        'max_critical_risk_events' => (int) env('SCORECARD_OPS_MAX_CRITICAL_RISK_EVENTS', 0),
        'max_rejected_rate_pct' => (float) env('SCORECARD_OPS_MAX_REJECTED_RATE_PCT', 10.0),
        'max_data_staleness_minutes' => (int) env('SCORECARD_OPS_MAX_DATA_STALENESS_MINUTES', 60),
    ],
];
