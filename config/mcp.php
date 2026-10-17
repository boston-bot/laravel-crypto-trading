<?php

return [
    'legacy_schedules_enabled' => filter_var(env('LEGACY_CRYPTO_SCHEDULES_ENABLED', false), FILTER_VALIDATE_BOOL),
    'monitoring_enabled' => filter_var(env('MCP_MONITORING_ENABLED', false), FILTER_VALIDATE_BOOL),
    'brokerage_writes_enabled' => filter_var(env('BROKERAGE_WRITES_ENABLED', false), FILTER_VALIDATE_BOOL),
    // These operators are explicitly trusted across all legacy accounts.
    'operator_emails' => array_values(array_filter(array_map('trim', explode(',', (string) env('MCP_OPERATOR_EMAILS', ''))))),
    'approver_emails' => array_values(array_filter(array_map('trim', explode(',', (string) env('TRADING_APPROVER_EMAILS', ''))))),
];
