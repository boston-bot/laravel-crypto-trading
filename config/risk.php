<?php

return [
    'max_daily_loss_pct' => (float) env('MAX_DAILY_LOSS_PCT', 2),
    'max_weekly_loss_pct' => (float) env('MAX_WEEKLY_LOSS_PCT', 5),
    'max_drawdown_pct' => (float) env('MAX_DRAWDOWN_PCT', 8),
    'max_position_notional_usd' => (float) env('MAX_POSITION_NOTIONAL_USD', 1000),
    'max_open_positions' => (int) env('MAX_OPEN_POSITIONS', 2),
    'max_daily_notional_pct' => (float) env('MAX_DAILY_NOTIONAL_PCT', 30),
    'stale_account_minutes' => (int) env('MAX_ACCOUNT_STALE_MINUTES', 30),
    'max_portfolio_heat_pct' => (float) env('MAX_PORTFOLIO_HEAT_PCT', 40),
    'max_asset_exposure_pct' => (float) env('MAX_ASSET_EXPOSURE_PCT', 20),
    'max_correlated_exposure_pct' => (float) env('MAX_CORRELATED_EXPOSURE_PCT', 70),
    'max_asset_atr_pct' => (float) env('MAX_ASSET_ATR_PCT', 0.10),
    'max_execution_penalty' => (float) env('MAX_EXECUTION_PENALTY', 0.70),
    'max_spread_bps' => (float) env('MAX_SPREAD_BPS', 180),
    'max_slippage_bps' => (float) env('MAX_SLIPPAGE_BPS', 180),
    'max_consecutive_losses' => (int) env('MAX_CONSECUTIVE_LOSSES', 3),
    'consecutive_loss_lookback' => (int) env('CONSECUTIVE_LOSS_LOOKBACK', 8),
];
