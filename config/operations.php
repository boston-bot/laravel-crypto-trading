<?php

// Blank .env values fall back to these defaults.
$home = rtrim((string) ($_SERVER['HOME'] ?? getenv('HOME') ?: ''), '/');

return [
    // Unattended operations (health checks, heartbeat, alerts, backups) register only on the deployed runtime.
    'enabled' => filter_var(env('MCP_OPERATIONS_ENABLED', false), FILTER_VALIDATE_BOOL),
    'ntfy' => [
        'url' => env('MCP_NTFY_URL') ?: 'https://ntfy.sh',
        // A long random topic name is the shared secret on a public ntfy server.
        'topic' => env('MCP_NTFY_TOPIC'),
        'token' => env('MCP_NTFY_TOKEN'),
    ],
    'healthchecks_url' => env('MCP_HEALTHCHECKS_PING_URL'),
    // Optional link in notifications, for example a private Tailscale address. Never an account identifier.
    'dashboard_url' => env('MCP_DASHBOARD_URL'),
    'escalation_minutes' => 30,
    'delivery_max_attempts' => 10,
    // Severity "critical" always alerts immediately; these warnings do too. Everything else goes to the daily digest.
    'immediate_keys' => ['thesis_breach', 'unknown_order_state', 'missed_monitoring_window', 'portfolio_stale', 'positions_stale', 'orders_stale'],
    'thresholds' => [
        'scheduler_stale_minutes' => 3,
        'queue_stall_minutes' => 15,
        'queue_probe_minutes' => 5,
        'queue_age_warning_minutes' => 10,
        'disk_warning_gib' => 5,
        'disk_critical_gib' => 1,
        'backup_max_age_hours' => 26,
    ],
    'backup' => [
        'dir' => env('MCP_BACKUP_DIR') ?: storage_path('app/backups'),
        'key_file' => env('MCP_BACKUP_KEY_FILE') ?: $home.'/.config/investing-ops/backup.key',
        // For example a Google Drive for desktop folder. Only encrypted files are copied there.
        'offsite_dir' => env('MCP_BACKUP_OFFSITE_DIR'),
        'pg_bin' => env('MCP_PG_BIN_DIR') ?: '/opt/homebrew/opt/postgresql@18/bin',
        'keep_daily' => 14,
        'keep_weekly' => 8,
        // The restore drill creates and drops a uniquely named scratch database with this login (local socket by default).
        'drill_user' => env('MCP_RESTORE_DRILL_DB_USER'),
        'drill_host' => env('MCP_RESTORE_DRILL_DB_HOST') ?: '/tmp',
    ],
];
