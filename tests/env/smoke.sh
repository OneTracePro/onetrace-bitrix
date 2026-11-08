#!/usr/bin/env bash
# Storefront check over HTTP after tests/env/run.sh: the tracker in <head>, /cdp-sw.js from the site root, a product
# view from the view counter of catalog.element, the recommendation widget component, the "Notify me when available" form,
# the place of "Ask AI about this product", the store MCP server with the order quote of AI assistants, the chat token and signed
# user id services, the search widget and its cart service, cart links of AI assistants.
set -euo pipefail
cd "$(dirname "$0")"
PORT="${BITRIX_PORT:-8895}"
exec_php() { docker compose exec -T web php /var/www/html/local/modules/onetrace.connector/tests/env/smoke-setup.php "$@"; }

read -r PRODUCT SECTION SOLD_OUT MCP_KEY <<<"$(exec_php)"
HOME_PAGE=$(curl -fsS "http://localhost:$PORT/")
WORKER=$(curl -fsS "http://localhost:$PORT/cdp-sw.js")
WIDGET=$(curl -fsS "http://localhost:$PORT/onetrace-widget.php")
NOTIFY=$(curl -fsS "http://localhost:$PORT/onetrace-notify.php")
curl -fsS -o /dev/null -A 'Mozilla/5.0 (X11; Linux x86_64) Chrome/140.0' -b 'cdp_aid=smoke-browser' \
    -d "AJAX=Y&SITE_ID=s1&PRODUCT_ID=$PRODUCT&PARENT_ID=$PRODUCT" "http://localhost:$PORT/bitrix/components/bitrix/catalog.element/ajax.php"
QUEUE=$(exec_php queue)
SEARCH_STATUS=$(curl -s -o /dev/null -w '%{http_code}' "http://localhost:$PORT/onetrace-search.php?q=smoke")
CATEGORY=$(curl -s -o /dev/null -w '%{redirect_url}' "http://localhost:$PORT/bitrix/services/onetrace.connector/category.php?id=$SECTION")

MCP_INIT='{"jsonrpc":"2.0","id":1,"method":"initialize","params":{}}'
MCP_URL="http://localhost:$PORT/bitrix/services/onetrace.connector/mcp.php?site=s1"
MCP_DENIED=$(curl -sS -o /dev/null -w '%{http_code}' -X POST -H 'Content-Type: application/json' -H 'Authorization: Bearer wrong' -d "$MCP_INIT" "$MCP_URL")
MCP=$(curl -fsS -X POST -H 'Content-Type: application/json' -H "Authorization: Bearer $MCP_KEY" -d "$MCP_INIT" "$MCP_URL")
MCP_QUOTE=$(curl -fsS -X POST -H 'Content-Type: application/json' -H "Authorization: Bearer $MCP_KEY" \
    -d "{\"jsonrpc\":\"2.0\",\"id\":2,\"method\":\"tools/call\",\"params\":{\"name\":\"store_quote_order\",\"arguments\":{\"items\":[{\"product_id\":\"$PRODUCT\",\"quantity\":2}]},\"_meta\":{\"onetrace/source\":\"assistant\",\"onetrace/assistant\":\"chatgpt\"}}}" "$MCP_URL")
CHAT_GUEST=$(curl -fsS "http://localhost:$PORT/bitrix/services/onetrace.connector/chat-customer.php?site=s1")
VISITOR_GUEST=$(curl -fsS "http://localhost:$PORT/bitrix/services/onetrace.connector/visitor.php?site=s1")
# A cart link of a guest: the product into the cart (the sold out one skipped), the cart page with the utm tags.
LINK_JAR=$(mktemp)
LINK=$(curl -sS -o /dev/null -D - -c "$LINK_JAR" -b "$LINK_JAR" "http://localhost:$PORT/?utm_source=chatgpt&onetrace_cart=$PRODUCT:2,$SOLD_OUT:1&utm_medium=ai_assistant" | tr -d '\r')
LINK_CART=$(curl -fsS -c "$LINK_JAR" -b "$LINK_JAR" "http://localhost:$PORT/personal/cart/")
rm -f "$LINK_JAR"
LINK_NONE=$(curl -sS -o /dev/null -w '%{http_code} %{redirect_url}' "http://localhost:$PORT/catalog/?onetrace_cart=$SOLD_OUT:1&utm_source=chatgpt")

# A fake platform inside the container answers the search with a redirect rule.
docker compose exec -T web sh -c 'printf "%s" "<?php header(\"Content-Type: application/json\"); echo json_encode([\"enabled\" => true, \"total\" => 0, \"items\" => [], \"redirect\" => \"http://localhost/delivery/\"]);" > /tmp/platform.php; (php -S 127.0.0.1:9999 /tmp/platform.php >/dev/null 2>&1 &)'
sleep 1
exec_php platform http://127.0.0.1:9999
REDIRECT=$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' "http://localhost:$PORT/onetrace-search.php?q=delivery")
# The search widget enabled on the fake platform: the container of the tracker and "Add to cart" of the module.
exec_php widget Y
WIDGET_SEARCH=$(curl -fsS "http://localhost:$PORT/onetrace-search.php?q=smoke")
WIDGET_HOME=$(curl -fsS "http://localhost:$PORT/")
CART_URL="http://localhost:$PORT/bitrix/services/onetrace.connector/cart.php?site=s1&id=$PRODUCT"
CART_JAR=$(mktemp)
CART_FOREIGN=$(curl -sS -o /dev/null -w '%{http_code}' -X POST -d quantity=1 "$CART_URL")
CART_GET=$(curl -sS -o /dev/null -w '%{http_code}' -H 'X-Requested-With: XMLHttpRequest' "$CART_URL")
CART=$(curl -fsS -c "$CART_JAR" -b "$CART_JAR" -X POST -H 'X-Requested-With: XMLHttpRequest' -d quantity=2 "$CART_URL")
rm -f "$CART_JAR"
exec_php widget N
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
check "store MCP refuses another key" "$MCP_DENIED" "401"
check "store MCP contract" "$MCP" '"onetrace/store":{"version":2}'
check "store MCP quote of an AI assistant" "$MCP_QUOTE" "\"structuredContent\":{\"items\":[{\"product_id\":\"$PRODUCT\",\"name\":\"Smoke\",\"quantity\":2,"
check "chat token script" "$HOME_PAGE" "onetrace_chat"
check "place of “Ask AI about this product”" "$HOME_PAGE" "d.setAttribute('data-cdp-ask','')"
check "no chat token for a guest" "$CHAT_GUEST" '{"token":null}'
check "signed user id script" "$HOME_PAGE" "visitor.php?site=s1"
check "no identity key in the page" "$(grep -c cdp_ik_smoke <<<"$HOME_PAGE" || true)" "0"
check "no signed user id for a guest" "$VISITOR_GUEST" '{"userId":null,"userHash":null}'
check "search widget container" "$WIDGET_SEARCH" '<div data-cdp-search-results data-query-param="q" data-cart-url="/bitrix/services/onetrace.connector/cart.php?site=s1&amp;id={id}"></div>'
check "search widget refreshes the mini-cart" "$WIDGET_HOME" "BX.onCustomEvent('OnBasketChange')"
check "search widget keeps the search fields" "$WIDGET_HOME" "data-cdp-search"
check "cart refuses a request without X-Requested-With" "$CART_FOREIGN" "400"
check "cart takes POST only" "$CART_GET" "405"
check "cart adds the product" "$CART" '{"ok":true,"count":1}'
check "cart link redirects to the cart with the utm tags" "$LINK" "Location: http://localhost:$PORT/personal/cart/?utm_source=chatgpt&utm_medium=ai_assistant"
check "cart link answer is not cached" "$LINK" "Cache-Control: no-store"
check "cart link fills the cart of the guest" "$LINK_CART" "'PRODUCT_ID':'$PRODUCT','~PRODUCT_ID':'$PRODUCT','QUANTITY':2"
check "cart link skips the sold out product" "$(grep -c "'PRODUCT_ID':'$SOLD_OUT'" <<<"$LINK_CART" || true)" "0"
check "cart link with nothing added keeps the page" "$LINK_NONE" "302 http://localhost:$PORT/catalog/?utm_source=chatgpt"
