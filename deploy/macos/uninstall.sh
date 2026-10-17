#!/usr/bin/env bash
# Stops and removes the LaunchAgents. The deployed app, its .env, backups, runtime files and logs are kept.
set -euo pipefail

DOMAIN="gui/$(id -u)"
for name in research reconciliation monitoring scheduler nginx php-fpm; do
    label="local.investing-ops.$name"
    launchctl bootout "$DOMAIN/$label" 2>/dev/null || true
    rm -f "$HOME/Library/LaunchAgents/$label.plist"
done
echo "LaunchAgents removed; application data kept."
