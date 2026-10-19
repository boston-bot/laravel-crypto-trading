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
];
