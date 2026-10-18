<?php

return [
    'market_inputs_enabled' => filter_var(env('MCP_PAPER_MARKET_INPUTS_ENABLED', false), FILTER_VALIDATE_BOOL),
    'handoff_enabled' => filter_var(env('MCP_PAPER_HANDOFF_ENABLED', false), FILTER_VALIDATE_BOOL),
    // Synthetic fills only. Proposal handoff requires its own independent gate and applied settings.
    'offline_simulation_enabled' => filter_var(env('MCP_PAPER_OFFLINE_SIMULATION_ENABLED', false), FILTER_VALIDATE_BOOL),
    // Independent opt-in. Existing research provider/model/credential/pricing must also be ready.
    'decisions_enabled' => filter_var(env('MCP_PAPER_DECISIONS_ENABLED', false), FILTER_VALIDATE_BOOL),
    'lease_seconds' => 180,
    'max_symbols' => 5,
    'max_sources' => 10,
    'max_excerpt_chars' => 1200,
    'max_input_bytes' => 32768,
    'quote_max_age_seconds' => 60,
];
