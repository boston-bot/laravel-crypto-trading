<?php

return [
    'poll_seconds' => (int) env('TRADING_RUNTIME_POLL_SECONDS', 2),
    'heartbeat_stale_seconds' => (int) env('TRADING_RUNTIME_STALE_SECONDS', 30),
    'drain_timeout_seconds' => (int) env('TRADING_RUNTIME_DRAIN_SECONDS', 15),
    'cycle_lease_seconds' => (int) env('PIPELINE_CYCLE_LEASE_SECONDS', 300),
    'account_freshness_minutes' => (int) env('OPERATIONS_ACCOUNT_FRESHNESS_MINUTES', 10),
    'local_only' => filter_var(env('OPERATIONS_LOCAL_ONLY', true), FILTER_VALIDATE_BOOL),
    'live_console_actions_enabled' => filter_var(env('LIVE_CONSOLE_ACTIONS_ENABLED', false), FILTER_VALIDATE_BOOL),
    'workloads' => [
        'scheduler' => [
            'label' => 'Laravel scheduler',
            'command' => [PHP_BINARY, base_path('artisan'), 'schedule:work', '--no-interaction'],
            'working_directory' => base_path(),
        ],
        'queue' => [
            'label' => 'Laravel queue worker',
            'command' => [PHP_BINARY, base_path('artisan'), 'queue:work', '--tries=3', '--timeout=120', '--no-interaction'],
            'working_directory' => base_path(),
        ],
        'engine' => [
            'label' => 'Python strategy engine',
            'command' => [env('TRADING_PYTHON_BINARY', base_path('backtest/venv/bin/python')), '-m', 'trading_engine.cli', 'worker'],
            'working_directory' => base_path('backtest'),
        ],
        'collector' => [
            'label' => 'Public market collector',
            'command' => [env('TRADING_PYTHON_BINARY', base_path('backtest/venv/bin/python')), '-m', 'trading_engine.cli', 'collect'],
            'working_directory' => base_path('backtest'),
        ],
    ],
];
