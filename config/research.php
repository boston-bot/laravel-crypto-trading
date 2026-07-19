<?php

return [
    'engine' => [
        'driver' => env('STRATEGY_ENGINE_DRIVER', 'legacy'),
        'schema_version' => env('STRATEGY_ENGINE_SCHEMA_VERSION', '1.0'),
        'version' => env('STRATEGY_ENGINE_VERSION', '0.1.0'),
        'lease_seconds' => (int) env('STRATEGY_ENGINE_LEASE_SECONDS', 300),
        'max_attempts' => (int) env('STRATEGY_ENGINE_MAX_ATTEMPTS', 3),
        'job_ttl_minutes' => (int) env('STRATEGY_ENGINE_JOB_TTL_MINUTES', 60),
        'proposal_ttl_minutes' => (int) env('STRATEGY_PROPOSAL_TTL_MINUTES', 30),
        'max_signal_age_minutes' => (int) env('STRATEGY_MAX_SIGNAL_AGE_MINUTES', 240),
    ],
    'universe' => ['BTC', 'ETH', 'SOL', 'LINK', 'LTC'],
    'candles' => [
        'canonical_source' => 'coinbase',
        'canonical_timeframe' => '1h',
        'derived_timeframes' => ['4h', '1d'],
        'target_years' => 5,
        'live_refresh_hours' => (int) env('MARKET_EVIDENCE_REFRESH_HOURS', 336),
    ],
    'fees' => [
        'max_age_minutes' => (int) env('FEE_SNAPSHOT_MAX_AGE_MINUTES', 60),
        'kraken_retail_taker_bps' => (float) env('KRAKEN_RETAIL_TAKER_BPS', 40.0),
    ],
    'books' => [
        'max_age_ms' => 1000,
        'max_receive_delta_ms' => 500,
        'notional_buckets' => [50, 100, 250, 500],
        'raw_retention_days' => 7,
    ],
    'spread' => [
        'safety_buffer_bps' => 10.0,
        'rebalance_reserve_bps' => (float) env('SPREAD_REBALANCE_RESERVE_BPS', 5.0),
        'delay_scenarios_ms' => [250, 500, 1000],
        'execution_enabled' => false,
    ],
    'sentiment' => [
        'url' => env('FEAR_GREED_API_URL', 'https://api.alternative.me/fng/'),
        'max_forward_fill_hours' => 36,
        'can_trigger_trade' => false,
    ],
    'approval' => [
        'max_quote_age_seconds' => (int) env('APPROVAL_MAX_QUOTE_AGE_SECONDS', 15),
        'max_price_change_bps' => (float) env('APPROVAL_MAX_PRICE_CHANGE_BPS', 75.0),
    ],
    'backtest_gate' => [
        'holdout_months' => 12,
        'train_months' => 24,
        'validation_months' => 6,
        'test_months' => 6,
        'step_months' => 6,
        'embargo_days' => 30,
        'min_oos_trades' => 100,
        'min_assets' => 3,
        'min_sharpe' => 0.8,
        'min_profit_factor' => 1.2,
        'max_drawdown_pct' => 8.0,
        'max_asset_profit_contribution_pct' => 50.0,
    ],
];
