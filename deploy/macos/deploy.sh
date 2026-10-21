#!/usr/bin/env bash
# Deploys a committed revision into the runtime checkout; uncommitted working-tree changes are never deployed.
# Usage: MCP_MIGRATION_CREDENTIALS_FILE=<private-file> deploy.sh <revision> [--migrate]
# Migrations run only when explicitly requested, using the separate verified schema owner.
set -euo pipefail
umask 077

BASE="${INVESTING_OPS_HOME:-$HOME/Services/investing-ops}"
APP="$BASE/app"
PORT="${INVESTING_OPS_PORT:-8080}"
BREW="${HOMEBREW_PREFIX:-/opt/homebrew}"
PHP_ROOT="$BREW/opt/php@8.5"
# Preserve a deliberately installed security runtime on later application deployments.
if [[ -e "$BASE/php/current" || -L "$BASE/php/current" ]]; then
    PHP_ROOT="$BASE/php/current"
fi
PHP="${PHP_BIN:-$PHP_ROOT/bin/php}"
REV="${1:?usage: deploy.sh <commit-or-branch> [--migrate]}"
MIGRATE="${2:-}"
DOMAIN="gui/$(id -u)"

cd "$APP"
if [[ -n "$(git status --porcelain --untracked-files=normal)" ]]; then
    echo "Runtime checkout has local changes; preserve and resolve them before deploying." >&2
    exit 1
fi
[[ -z "$MIGRATE" || "$MIGRATE" == "--migrate" ]] || { echo "Unknown deploy option." >&2; exit 1; }
git fetch --quiet origin
COMMIT="$(git rev-parse --verify --quiet --end-of-options "origin/$REV^{commit}" || git rev-parse --verify --end-of-options "$REV^{commit}")"
git -c advice.detachedHead=false checkout --quiet --detach "$COMMIT"

composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --quiet
npm ci --ignore-scripts --no-audit --no-fund --silent
npm run build --silent >/dev/null

"$PHP" artisan config:clear --quiet
"$PHP" artisan mcp:doctor >/dev/null || { echo "Database identity or schema check failed; nothing restarted." >&2; exit 1; }
if "$PHP" deploy/macos/migrate.php --check; then
    :
else
    STATUS=$?
    [[ "$STATUS" == 2 ]] || { echo "Migration status failed; deployment stopped." >&2; exit 1; }
    if [[ "$MIGRATE" != "--migrate" ]]; then
        echo "Pending migrations. Review with deploy/macos/migrate.php --pretend and private owner credentials, then rerun with --migrate." >&2
        exit 2
    fi
    "$PHP" deploy/macos/migrate.php --apply
fi

"$PHP" artisan optimize --quiet
"$PHP" artisan queue:restart --quiet
launchctl kickstart -k "$DOMAIN/local.investing-ops.php-fpm"
for _ in 1 2 3 4 5; do
    sleep 1
    if curl -fsS "http://127.0.0.1:$PORT/ready" >/dev/null 2>&1; then
        echo "Deployed $(git rev-parse --short HEAD); /ready is healthy."
        exit 0
    fi
done
echo "Deployed $(git rev-parse --short HEAD), but /ready did not respond; check $BASE/logs." >&2
exit 1
