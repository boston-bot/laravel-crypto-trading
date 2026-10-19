<?php

return [
    // Data reads only; never used by a brokerage execution adapter.
    'enabled' => filter_var(env('ALPACA_DATA_ENABLED', false), FILTER_VALIDATE_BOOL),
    'key' => env('ALPACA_KEY'),
    'secret' => env('ALPACA_SECRET'),
    'max_pages' => 10,
    'max_events' => 2000,
    'lookback_days' => 30,
    'lookahead_days' => 30,

    // Listing and daily-history reference reads for rule eligibility. Separately default-off.
    'reference_enabled' => filter_var(env('ALPACA_REFERENCE_ENABLED', false), FILTER_VALIDATE_BOOL),
    // Which Alpaca account host serves the read-only asset (listing) endpoint: "PK…" keys are paper, "AK…" keys live.
    'account_environment' => env('ALPACA_ACCOUNT_ENVIRONMENT', 'paper'),
    // 400 calendar days hold more than the 252 sessions eligibility requires.
    'history_lookback_days' => 400,
    'history_symbols_per_request' => 25,
    'history_max_pages' => 20,
    // A daily refresh keeps history current; older reads are unknown, not reused.
    'history_max_age_hours' => 30,
    'listing_max_age_days' => 7,

    // XNYS session calendar from the same account host. Separately default-off: it drives every session-aware loop.
    'calendar_enabled' => filter_var(env('ALPACA_CALENDAR_ENABLED', false), FILTER_VALIDATE_BOOL),
    'calendar_lookback_days' => 7,
    'calendar_lookahead_days' => 60,
];
