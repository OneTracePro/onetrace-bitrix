#!/usr/bin/env bash
# Storefront check over HTTP after tests/env/run.sh: the tracker in <head>, /cdp-sw.js from the site root, a product
# view from the view counter of catalog.element, the recommendation widget component.
set -euo pipefail
cd "$(dirname "$0")"
PORT="${BITRIX_PORT:-8895}"
exec_php() { docker compose exec -T web php /var/www/html/local/modules/onetrace.connector/tests/env/smoke-setup.php "$@"; }

PRODUCT=$(exec_php)
HOME_PAGE=$(curl -fsS "http://localhost:$PORT/")
WORKER=$(curl -fsS "http://localhost:$PORT/cdp-sw.js")
WIDGET=$(curl -fsS "http://localhost:$PORT/onetrace-widget.php")
curl -fsS -o /dev/null -A 'Mozilla/5.0 (X11; Linux x86_64) Chrome/140.0' -b 'cdp_aid=smoke-browser' \
    -d "AJAX=Y&SITE_ID=s1&PRODUCT_ID=$PRODUCT&PARENT_ID=$PRODUCT" "http://localhost:$PORT/bitrix/components/bitrix/catalog.element/ajax.php"
QUEUE=$(exec_php queue)

check() {
    if ! grep -qF -- "$3" <<<"$2"; then
        echo "FAIL: $1" >&2
        exit 1
    fi
    echo "ok: $1"
}

check "tracker in <head>" "$HOME_PAGE" "cdp.init(s.key,s.options)"
check "service worker" "$WORKER" 'importScripts("https://cdp.example.com/tracker/cdp-sw.js")'
check "recommendation widget" "$WIDGET" 'data-cdp-widget="k3x9" data-item="17,18" data-category="5"'
check "product_viewed" "$QUEUE" "\"event\":\"product_viewed\""
check "product of the view" "$QUEUE" "\"product_id\":\"$PRODUCT\""
