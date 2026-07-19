<?php

return [
    'enabled' => filter_var(env('TRADING_ENABLED', false), FILTER_VALIDATE_BOOL),
    'human_approval_required' => filter_var(env('HUMAN_APPROVAL_REQUIRED', true), FILTER_VALIDATE_BOOL),
    'strategy_name' => env('STRATEGY_NAME', 'BTC_ETH_Momentum_Filtered_v1'),
    'allowed_assets' => array_values(array_filter(array_map('trim', explode(',', env('TRADING_ALLOWED_ASSETS', 'BTC,ETH,SOL,LINK,LTC'))))),
    'max_open_positions' => (int) env('MAX_OPEN_POSITIONS', 2),
    'max_position_notional_usd' => (float) env('MAX_POSITION_NOTIONAL_USD', 1000),
    'max_daily_notional_pct' => (float) env('MAX_DAILY_NOTIONAL_PCT', 30),
    'max_new_entries_per_day' => (int) env('MAX_NEW_ENTRIES_PER_DAY', 1),
    'max_new_entries_per_week' => (int) env('MAX_NEW_ENTRIES_PER_WEEK', 3),
    'cooldown_minutes' => (int) env('TRADE_COOLDOWN_MINUTES', 30),
    'kill_switch_cache_key' => env('TRADING_KILL_SWITCH_CACHE_KEY', 'trading:frozen'),
    'universe' => [
        'require_history' => filter_var(env('TRADING_UNIVERSE_REQUIRE_HISTORY', false), FILTER_VALIDATE_BOOL),
        'minimum_candle_history' => (int) env('TRADING_MIN_CANDLE_HISTORY', 120),
    ],
    'regime' => [
        'btc_trend_threshold' => (float) env('REGIME_BTC_TREND_THRESHOLD', 0.58),
        'btc_momentum_threshold' => (float) env('REGIME_BTC_MOMENTUM_THRESHOLD', 0.55),
        'eth_confirmation_threshold' => (float) env('REGIME_ETH_CONFIRMATION_THRESHOLD', 0.52),
        'breadth_threshold' => (float) env('REGIME_BREADTH_THRESHOLD', 0.55),
        'vol_shock_atr_pct' => (float) env('REGIME_VOL_SHOCK_ATR_PCT', 0.08),
        'vol_shock_quality_threshold' => (float) env('REGIME_VOL_SHOCK_QUALITY_THRESHOLD', 0.35),
    ],
    'scoring' => [
        'weights' => [
            'trend' => (float) env('SCORING_WEIGHT_TREND', 0.30),
            'relative_strength' => (float) env('SCORING_WEIGHT_RELATIVE_STRENGTH', 0.25),
            'momentum' => (float) env('SCORING_WEIGHT_MOMENTUM', 0.20),
            'volatility_quality' => (float) env('SCORING_WEIGHT_VOLATILITY_QUALITY', 0.15),
            'pullback_quality' => (float) env('SCORING_WEIGHT_PULLBACK_QUALITY', 0.10),
            'participation' => (float) env('SCORING_WEIGHT_PARTICIPATION', 0.00),
            'execution_penalty' => (float) env('SCORING_WEIGHT_EXECUTION_PENALTY', 0.18),
        ],
    ],
    'entry' => [
        'max_universe_rank' => (int) env('ENTRY_MAX_UNIVERSE_RANK', 3),
        'max_rsi' => (float) env('ENTRY_MAX_RSI', 72),
        'max_extension_pct' => (float) env('ENTRY_MAX_EXTENSION_PCT', 0.03),
        'max_atr_pct' => (float) env('ENTRY_MAX_ATR_PCT', 0.09),
        'min_probability' => (float) env('ENTRY_MIN_PROBABILITY', 0.52),
    ],
    'exit' => [
        'max_rsi' => (float) env('EXIT_MAX_RSI', 78),
    ],
    'sizing' => [
        'base_risk_pct' => (float) env('SIZING_BASE_RISK_PCT', 0.005),
        'neutral_regime_multiplier' => (float) env('SIZING_NEUTRAL_REGIME_MULTIPLIER', 0.60),
        'atr_stop_multiple' => (float) env('SIZING_ATR_STOP_MULTIPLE', 1.8),
        'default_atr_pct' => (float) env('SIZING_DEFAULT_ATR_PCT', 0.03),
        'min_stop_distance_pct' => (float) env('SIZING_MIN_STOP_DISTANCE_PCT', 0.015),
        'drawdown_scale_pct' => (float) env('SIZING_DRAWDOWN_SCALE_PCT', 12.0),
        'max_position_pct_of_equity' => (float) env('SIZING_MAX_POSITION_PCT_EQUITY', 0.20),
    ],
    'paper' => [
        'default_spread_bps' => (float) env('PAPER_DEFAULT_SPREAD_BPS', 35.0),
        'taker_fee_bps' => (float) env('PAPER_TAKER_FEE_BPS', 60.0),
        'fee_scenario' => env('PAPER_FEE_SCENARIO', 'coinbase_taker_snapshot'),
        'slippage_scenario' => env('PAPER_SLIPPAGE_SCENARIO', 'observed_quote'),
        'human_approval_required' => filter_var(env('PAPER_HUMAN_APPROVAL_REQUIRED', false), FILTER_VALIDATE_BOOL),
    ],
    'market_data' => [
        'span_1d' => env('MARKET_DATA_CANDLE_SPAN_1D', 'year'),
        'span_4h' => env('MARKET_DATA_CANDLE_SPAN_4H', '3month'),
    ],
];
