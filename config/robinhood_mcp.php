<?php

return [
    'enabled' => filter_var(env('ROBINHOOD_MCP_ENABLED', false), FILTER_VALIDATE_BOOL),
    'watchlists_enabled' => filter_var(env('ROBINHOOD_MCP_WATCHLISTS_ENABLED', false), FILTER_VALIDATE_BOOL),
    'client_id' => env('ROBINHOOD_MCP_CLIENT_ID'),
    'redirect_uri' => env('ROBINHOOD_MCP_REDIRECT_URI'),
    'max_pages' => 20,
    'max_records' => 10000,
    'max_age_seconds' => 300,
    // Reviewed application code/configuration only. Never populate from model or broker prose.
    'operations' => [
        // Reviewed from the app-owned capability catalog, October 18, 2026.
        // These lists belong to the authorized Robinhood user, not the bound agentic account.
        'watchlists' => [
            'tool' => 'get_watchlists', 'reviewed_read_only' => true,
            'fingerprint' => 'b7238914b576828e632bdf7225bbc8c66b7ee1e391886405964d673ec9fa4fde',
            'records' => 'data.watchlists', 'pagination' => ['kind' => 'single'],
            'normalizer' => 'robinhood_v1', 'coverage' => 'authorized_user_watchlists',
        ],
        'watchlist_items' => [
            'tool' => 'get_watchlist_items', 'reviewed_read_only' => true,
            'fingerprint' => '08d364ccb828f8fcfd5eafc467e21bd62663ba04828504295cfd15ebbdfbaf6e',
            'filters' => ['list_id' => 'list_id'], 'required_filter' => 'list_id',
            'records' => 'data.items', 'pagination' => ['kind' => 'single'],
            'normalizer' => 'robinhood_v1', 'coverage' => 'authorized_user_watchlist_items',
        ],
        // Reviewed against the app-authorized catalog on October 16, 2026.
        'accounts' => [
            'tool' => 'get_accounts',
            'reviewed_read_only' => true,
            'fingerprint' => '1a14050735efd776b035877a0c22cf197e1c7eac124dedd1e38e9d5f34ed7ed8',
            'records' => 'data.accounts',
            'pagination' => ['kind' => 'single'],
            'normalizer' => 'robinhood_accounts_v1',
        ],
        'portfolio' => [
            'tool' => 'get_portfolio', 'reviewed_read_only' => true,
            'fingerprint' => '46bd20cca280a145cea1687cb95b79ff9f7ea3820e1d78a7bba0464e0a69ebd7',
            'account_argument' => 'account_number', 'records' => 'data', 'records_object' => true,
            'pagination' => ['kind' => 'single'], 'normalizer' => 'robinhood_v1', 'coverage' => 'account_totals',
        ],
        'positions' => [
            'tool' => 'get_equity_positions', 'reviewed_read_only' => true,
            'fingerprint' => 'a272ab4650e5ee04f76dc5fceb66ac922a39cb3c501a7ce6cb67b54780e81a94',
            'account_argument' => 'account_number', 'records' => 'data.positions',
            'pagination' => ['kind' => 'cursor', 'argument' => 'cursor', 'next' => 'data.next', 'missing_is_terminal' => true, 'empty_is_terminal' => true, 'broker_cursor' => true],
            'normalizer' => 'robinhood_v1', 'coverage' => 'equities',
        ],
        'orders' => [
            'tool' => 'get_equity_orders', 'reviewed_read_only' => true,
            'fingerprint' => 'fec6180ea517e0f6003252c95d5a1903147e504299024b94b1ae307f3361a194',
            'account_argument' => 'account_number', 'records' => 'data.orders',
            'filters' => ['order_id' => 'order_id', 'symbol' => 'symbol'],
            'pagination' => ['kind' => 'cursor', 'argument' => 'cursor', 'next' => 'data.next', 'missing_is_terminal' => true, 'empty_is_terminal' => true, 'broker_cursor' => true],
            'normalizer' => 'robinhood_v1', 'coverage' => 'equities',
        ],
        'instruments' => [
            'tool' => 'search', 'reviewed_read_only' => true,
            'fingerprint' => '17a087a859150e2b20f8a9a00239ba7cdd0fe276c9a516025209bdf8728dc10d',
            'arguments' => ['limit' => 20], 'filters' => ['symbol' => 'query'], 'required_filter' => 'symbol',
            'records' => 'data.results', 'pagination' => ['kind' => 'single'],
            'normalizer' => 'robinhood_v1', 'exact_symbol' => true, 'coverage' => 'equity_symbol_lookup',
        ],
        'quotes' => [
            'tool' => 'get_equity_quotes', 'reviewed_read_only' => true,
            'fingerprint' => 'ae164ba8c6d2b1bfd9902cb292cd67f8f1ddbae6095daff4fedce7f5e1dc4bf1',
            'filters' => ['symbol' => 'symbols'], 'array_filters' => ['symbol'], 'required_filter' => 'symbol',
            'records' => 'data.results', 'pagination' => ['kind' => 'single'],
            'normalizer' => 'robinhood_v1', 'exact_symbol' => true, 'coverage' => 'equity_quotes',
        ],
    ],
];
