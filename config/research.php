<?php

return [
    // Disabled until the owner supplies a reviewed provider, model, credential and cost basis. Monitoring never depends on it.
    'enabled' => filter_var(env('MCP_RESEARCH_ENABLED', false), FILTER_VALIDATE_BOOL),
    'provider' => env('MCP_RESEARCH_PROVIDER', 'openai'),
    // No default model is invented; the owner must name a model their application credential can access.
    'model' => env('MCP_RESEARCH_MODEL'),
    // Application-specific name so a developer-tool or shell credential is not silently reused.
    'api_key' => env('MCP_RESEARCH_API_KEY'),
    'timeout_seconds' => 60,
    'max_output_tokens' => 2000,
    'cache_hours' => 24,
    'max_sources' => 8,
    'max_excerpt_chars' => 4000,
    'source_window_days' => 30,
    'event_expiry_hours' => 72,
    'lease_minutes' => 5,
    // Reviewed provider pricing as quoted decimal USD. Unset means no reservation basis, so research stays unavailable.
    'input_cost_per_million' => env('MCP_RESEARCH_INPUT_COST_PER_MTOK'),
    'output_cost_per_million' => env('MCP_RESEARCH_OUTPUT_COST_PER_MTOK'),
    // All fixed AI/data subscriptions for the month, including zero-usage plans. Must be explicitly reviewed, e.g. "0.00".
    'fixed_monthly_costs' => env('MCP_RESEARCH_FIXED_MONTHLY_COSTS'),
    'monthly_envelope' => env('MCP_RESEARCH_MONTHLY_ENVELOPE', '100.00'),
    'envelope_ceiling' => '150.00',
    'reduce_at' => '0.80',
    // Documented coverage of the only configured market-data source. Unsupported fields are unknown, never inferred.
    'market_data' => [
        'provider' => 'broker:robinhood_mcp',
        'coverage' => [
            'quotes' => ['supported' => true, 'timestamp_quality' => 'broker component source times for bid/ask/last; regular and extended sessions labeled', 'scope' => 'requested equity symbols'],
            'account_tradability' => ['supported' => true, 'timestamp_quality' => 'retrieval only; broker supplies no source time', 'scope' => 'source mapping behind separate default-off execution-input read gate; not strategy eligibility'],
            'price_book' => ['supported' => true, 'timestamp_quality' => 'reviewed broker snapshot time, not quote-component venue times', 'scope' => 'source mapping behind separate default-off gate; reviewed share units require matching fresh quote/book evidence'],
            'strategy_classification' => ['supported' => false, 'timestamp_quality' => 'none', 'scope' => 'stock/ETF and leveraged/inverse classification not supplied by reviewed contracts'],
            'instrument_lookup' => ['supported' => true, 'timestamp_quality' => 'retrieval time only', 'scope' => 'exact equity symbol match'],
            'market_sessions' => ['supported' => false, 'timestamp_quality' => 'none', 'scope' => 'no reviewed exchange-session read'],
            'daily_bars' => ['supported' => false, 'timestamp_quality' => 'none', 'scope' => 'no reviewed completed-bar read'],
            'corporate_actions' => ['supported' => false, 'timestamp_quality' => 'none', 'scope' => 'not reviewed'],
            'fundamentals' => ['supported' => false, 'timestamp_quality' => 'none', 'scope' => 'not reviewed'],
            'earnings_dates' => ['supported' => false, 'timestamp_quality' => 'none', 'scope' => 'advertised by broker catalog; no reviewed mapping or captured contract'],
            'news' => ['supported' => false, 'timestamp_quality' => 'none', 'scope' => 'no licensed news source configured'],
        ],
        'operator_evidence' => ['supported' => true, 'timestamp_quality' => 'operator-stated publication time; retrieval time at entry', 'scope' => 'untrusted excerpts for held, watched or thesis symbols'],
    ],
];
