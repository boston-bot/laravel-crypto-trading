<?php

return [
    // Public regulatory classification reads only; never used by a brokerage execution adapter.
    'enabled' => filter_var(env('SEC_EDGAR_ENABLED', false), FILTER_VALIDATE_BOOL),
    // SEC fair-access policy requires a declared automated-tool identity with an operator contact address.
    'user_agent' => env('SEC_EDGAR_USER_AGENT'),
    'request_interval_ms' => 150,
    'max_mapping_bytes' => 8 * 1024 * 1024,
    'max_document_bytes' => 40 * 1024 * 1024,
    'max_ncen_documents' => 8,
    'max_history_pages' => 2,
    // Annual N-CEN reports are due within 75 days of fiscal year end: 365 + 75 + 15 days of slack.
    'report_max_age_days' => 455,
    // Stored classifications older than this are unknown; the scheduled refresh runs daily.
    'classification_max_age_days' => 7,
];
