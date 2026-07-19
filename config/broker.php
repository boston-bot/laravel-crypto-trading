<?php

return [
    'default' => env('BROKER', 'coinbase'),
    'mode' => env('BROKER_MODE', 'paper'),

    'coinbase' => [
        'base_url' => env('COINBASE_BASE_URL', 'https://api.coinbase.com'),
        'api_key' => env('COINBASE_API_KEY'),
        'api_private_key' => env('COINBASE_API_PRIVATE_KEY'),
        'quote_currency' => env('COINBASE_QUOTE_CURRENCY', 'USD'),
        'timeout_seconds' => (int) env('COINBASE_TIMEOUT_SECONDS', 10),
        'retry' => [
            'times' => (int) env('COINBASE_RETRY_TIMES', 3),
            'sleep_ms' => (int) env('COINBASE_RETRY_SLEEP_MS', 200),
        ],
        'endpoints' => [
            'accounts' => env('COINBASE_ENDPOINT_ACCOUNTS', '/api/v3/brokerage/accounts'),
            'assets' => env('COINBASE_ENDPOINT_ASSETS', '/api/v3/brokerage/products'),
            'orders' => env('COINBASE_ENDPOINT_ORDERS', '/api/v3/brokerage/orders/historical/batch'),
            'order' => env('COINBASE_ENDPOINT_ORDER', '/api/v3/brokerage/orders/historical'),
            'place_order' => env('COINBASE_ENDPOINT_PLACE_ORDER', '/api/v3/brokerage/orders'),
            'quotes' => env('COINBASE_ENDPOINT_QUOTES', '/api/v3/brokerage/best_bid_ask'),
            'candles' => env('COINBASE_ENDPOINT_CANDLES', '/api/v3/brokerage/products/{product_id}/candles'),
            'transaction_summary' => env('COINBASE_ENDPOINT_TRANSACTION_SUMMARY', '/api/v3/brokerage/transaction_summary'),
        ],
    ],

    'robinhood' => [
        'base_url' => env('ROBINHOOD_BASE_URL', 'https://trading.robinhood.com'),
        'api_key' => env('ROBINHOOD_API_KEY'),
        'api_secret' => env('ROBINHOOD_API_SECRET'),
        'public_api_key' => env('ROBINHOOD_PUBLIC_API_KEY', env('ROBINHOOD_API_KEY')),
        'private_api_key' => env('ROBINHOOD_PRIVATE_API_KEY', env('ROBINHOOD_SIGNING_PRIVATE_KEY', env('ROBINHOOD_API_KEY'))),
        'x_api_key' => env('ROBINHOOD_X_API_KEY', env('ROBINHOOD_API_SECRET')),
        'timeout_seconds' => (int) env('ROBINHOOD_TIMEOUT_SECONDS', 10),
        'retry' => [
            'times' => (int) env('ROBINHOOD_RETRY_TIMES', 3),
            'sleep_ms' => (int) env('ROBINHOOD_RETRY_SLEEP_MS', 200),
        ],
        'endpoints' => [
            'accounts' => env('ROBINHOOD_ENDPOINT_ACCOUNTS', '/api/v1/crypto/trading/accounts/'),
            'assets' => env('ROBINHOOD_ENDPOINT_ASSETS', '/api/v1/crypto/trading/trading_pairs/'),
            'positions' => env('ROBINHOOD_ENDPOINT_POSITIONS', '/api/v1/crypto/trading/holdings/'),
            'orders' => env('ROBINHOOD_ENDPOINT_ORDERS', '/api/v1/crypto/trading/orders/'),
            'quotes' => env('ROBINHOOD_ENDPOINT_QUOTES', '/api/v1/crypto/marketdata/best_bid_ask/'),
            'candles' => env('ROBINHOOD_ENDPOINT_CANDLES', '/api/v1/crypto/marketdata/historicals/'),
        ],
    ],
];
