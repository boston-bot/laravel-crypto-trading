<?php

return [
    // C2 stored-data evidence only. No schedule, paper handoff or broker mutation is attached to this switch.
    'strategy_evaluation_enabled' => filter_var(env('CRYPTO_STRATEGY_EVALUATION_ENABLED', false), FILTER_VALIDATE_BOOL),
    // Crypto track C1 (CRYPTO-AUTOMATION-PLAN.md): read-only Coinbase public market data. Default off.
    // Never used by an execution adapter; no credentials are sent.
    'market_data_enabled' => filter_var(env('CRYPTO_MARKET_DATA_ENABLED', false), FILTER_VALIDATE_BOOL),

    // Products to collect, e.g. "BTC-USD,ETH-USD". This is a collection list, not a trading universe (plan C2).
    'coinbase_products' => array_values(array_filter(array_map('trim', explode(',', (string) env('COINBASE_MARKET_DATA_PRODUCTS', ''))))),
    'max_products' => 25,

    // Trailing five-minute window re-read every cycle; closed candles only.
    'intraday_lookback_minutes' => 120,
    // UTC daily candles; refreshed when the newest stored closed day is older than yesterday.
    'daily_lookback_days' => 400,

    // A quote older than this is unknown at the point of use.
    'quote_max_age_seconds' => 120,
    // Health turns critical when no complete read is this recent while collection is enabled.
    'collection_max_age_minutes' => 15,
];
