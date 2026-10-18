#!/usr/bin/env bash
# Storefront check over HTTP after tests/env/run.sh: the tracker in <head>, /cdp-sw.js from the site root, a product
# view from the view counter of catalog.element, the recommendation widget component, the "Notify me when available" form.
set -euo pipefail
cd "$(dirname "$0")"
PORT="${BITRIX_PORT:-8895}"
exec_php() { docker compose exec -T web php /var/www/html/local/modules/onetrace.connector/tests/env/smoke-setup.php "$@"; }

read -r PRODUCT SECTION SOLD_OUT <<<"$(exec_php)"
HOME_PAGE=$(curl -fsS "http://localhost:$PORT/")
WORKER=$(curl -fsS "http://localhost:$PORT/cdp-sw.js")
WIDGET=$(curl -fsS "http://localhost:$PORT/onetrace-widget.php")
NOTIFY=$(curl -fsS "http://localhost:$PORT/onetrace-notify.php")
curl -fsS -o /dev/null -A 'Mozilla/5.0 (X11; Linux x86_64) Chrome/140.0' -b 'cdp_aid=smoke-browser' \
    -d "AJAX=Y&SITE_ID=s1&PRODUCT_ID=$PRODUCT&PARENT_ID=$PRODUCT" "http://localhost:$PORT/bitrix/components/bitrix/catalog.element/ajax.php"
QUEUE=$(exec_php queue)
SEARCH_STATUS=$(curl -s -o /dev/null -w '%{http_code}' "http://localhost:$PORT/onetrace-search.php?q=smoke")
CATEGORY=$(curl -s -o /dev/null -w '%{redirect_url}' "http://localhost:$PORT/bitrix/services/onetrace.connector/category.php?id=$SECTION")

# A fake platform inside the container answers the search with a redirect rule.
docker compose exec -T web sh -c 'printf "%s" "<?php header(\"Content-Type: application/json\"); echo json_encode([\"total\" => 0, \"items\" => [], \"redirect\" => \"http://localhost/delivery/\"]);" > /tmp/platform.php; (php -S 127.0.0.1:9999 /tmp/platform.php >/dev/null 2>&1 &)'
sleep 1
exec_php platform http://127.0.0.1:9999
REDIRECT=$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' "http://localhost:$PORT/onetrace-search.php?q=delivery")
exec_php platform https://cdp.example.com

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
check "notify when available" "$NOTIFY" "<div data-cdp-notify=\"$SOLD_OUT\"></div>"
check "product_viewed" "$QUEUE" "\"event\":\"product_viewed\""
check "product of the view" "$QUEUE" "\"product_id\":\"$PRODUCT\""
check "search box suggestions" "$HOME_PAGE" "data-cdp-search"
check "search page falls back on platform errors" "$SEARCH_STATUS" "200"
check "category of a suggestion" "$CATEGORY" "/catalog/smoke-section-"
check "search redirect rule" "$REDIRECT" "302 http://localhost/delivery/"
