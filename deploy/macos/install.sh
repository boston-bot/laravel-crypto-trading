#!/usr/bin/env bash
# Installs the runtime services for the deployed checkout as user LaunchAgents: no sudo, idempotent.
# Layout: $INVESTING_OPS_HOME/{app,runtime,logs}. Herd, Valet and Homebrew service configurations are not modified.
set -euo pipefail

BASE="${INVESTING_OPS_HOME:-$HOME/Services/investing-ops}"
APP="$BASE/app"
RUNTIME="$BASE/runtime"
LOGS="$BASE/logs"
PORT="${INVESTING_OPS_PORT:-8080}"
BREW="${HOMEBREW_PREFIX:-/opt/homebrew}"
PHP="${PHP_BIN:-$BREW/opt/php@8.5/bin/php}"
PHP_FPM="${PHP_FPM_BIN:-$BREW/opt/php@8.5/sbin/php-fpm}"
NGINX="${NGINX_BIN:-$BREW/bin/nginx}"
NGINX_CONF_DIR="$BREW/etc/nginx"
AGENTS="$HOME/Library/LaunchAgents"
TEMPLATES="$(cd "$(dirname "$0")" && pwd)/templates"
DOMAIN="gui/$(id -u)"
SERVICES=(php-fpm nginx scheduler monitoring reconciliation research)

[[ "$BASE" != *[[:space:]#]* ]] || { echo "Choose a runtime path without spaces or '#'." >&2; exit 1; }
[[ -f "$APP/artisan" && -f "$APP/.env" ]] || { echo "Deploy the app (with its .env) to $APP first." >&2; exit 1; }
for bin in "$PHP" "$PHP_FPM" "$NGINX"; do
    [[ -x "$bin" ]] || { echo "Missing executable: $bin" >&2; exit 1; }
done
# Fail before touching services if this release could activate a legacy or write-capable path.
cd "$APP"
"$PHP" -r '
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$unsafe = config("app.debug") || config("app.env") !== "production";
foreach (["mcp.brokerage_writes_enabled", "mcp.legacy_schedules_enabled", "trading.enabled", "research.enabled"] as $flag) {
    $unsafe = $unsafe || config($flag);
}
if ($unsafe) { fwrite(STDERR, "Runtime requires production mode, debug off, and research/legacy/broker writes disabled.\n"); exit(1); }
' || exit 1
"$PHP" artisan mcp:doctor >/dev/null || { echo "Runtime database/schema check failed." >&2; exit 1; }

if lsof -nP -iTCP:"$PORT" -sTCP:LISTEN -t 2>/dev/null | grep -q .; then
    own_pid="$(cat "$RUNTIME/nginx.pid" 2>/dev/null || true)"
    if [[ ! "$own_pid" =~ ^[0-9]+$ ]] || ! lsof -nP -a -p "$own_pid" -iTCP:"$PORT" -sTCP:LISTEN -t >/dev/null 2>&1; then
        echo "Port $PORT belongs to another service; stop only the intended development listener first." >&2
        exit 1
    fi
fi

mkdir -p "$RUNTIME"/nginx/{client_body,fastcgi,proxy,uwsgi,scgi} "$LOGS" "$AGENTS"
chmod 700 "$BASE" "$RUNTIME" "$LOGS"

render() {
    sed -e "s#@@APP@@#$APP#g" -e "s#@@RUNTIME@@#$RUNTIME#g" -e "s#@@LOGS@@#$LOGS#g" -e "s#@@PORT@@#$PORT#g" \
        -e "s#@@PHP@@#$PHP#g" -e "s#@@PHP_FPM@@#$PHP_FPM#g" -e "s#@@NGINX@@#$NGINX#g" \
        -e "s#@@NGINX_CONF_DIR@@#$NGINX_CONF_DIR#g" -e "s#@@BREW@@#$BREW#g" "$1"
}

render "$TEMPLATES/nginx.conf" > "$RUNTIME/nginx.conf"
render "$TEMPLATES/php-fpm.conf" > "$RUNTIME/php-fpm.conf"
"$NGINX" -t -q -c "$RUNTIME/nginx.conf" -p "$RUNTIME" -e "$LOGS/nginx-error.log"
"$PHP_FPM" -t --fpm-config "$RUNTIME/php-fpm.conf" >/dev/null 2>&1 || { echo "PHP-FPM configuration test failed." >&2; exit 1; }

for name in "${SERVICES[@]}"; do
    label="local.investing-ops.$name"
    plist="$AGENTS/$label.plist"
    render "$TEMPLATES/launchd/$name.plist" > "$plist.tmp"
    plutil -lint -s "$plist.tmp"
    launchctl bootout "$DOMAIN/$label" 2>/dev/null || true
    mv "$plist.tmp" "$plist"
    launchctl bootstrap "$DOMAIN" "$plist"
done

echo "Installed ${#SERVICES[@]} LaunchAgents. Verify with:"
echo "  launchctl list | grep investing-ops"
echo "  curl -s http://127.0.0.1:$PORT/ready"
