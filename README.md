# OneTrace.pro for 1C-Bitrix

[![CI](https://github.com/OneTracePro/onetrace-bitrix/actions/workflows/ci.yml/badge.svg)](https://github.com/OneTracePro/onetrace-bitrix/actions/workflows/ci.yml)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

Connects a 1C-Bitrix "Site management" store to [OneTrace.pro](https://onetrace.pro), the customer data platform: orders, customers and newsletter consents from the server, the website tracker, product views, the product catalog, recommendation widgets, Web Push and the store emails. Abandoned cart, browse abandonment, post-purchase and win-back journeys, product recommendations and predictions work right after you connect the store.

[Русская версия](README.ru.md)

- 1C-Bitrix "Site management" with the modules "Online store" and "Commercial catalog" (editions "Small business" and higher), PHP 8.2–8.5, UTF-8
- Orders and their statuses are sent from the server through a queue with retries (an agent every minute): the checkout never waits for the platform
- Settings per site: each site can send to its own project
- The same events and properties as every OneTrace shop plugin ([e-commerce contract](https://onetrace.pro/en/docs/api)), built on [onetrace-php](https://github.com/OneTracePro/onetrace-php), bundled inside the module

## What it sends

| Data | When | How |
|---|---|---|
| `identify` — email, phone, name, city, country, language | registration, login, profile changes, every order | server, secret key |
| Newsletter consent | subscribing and unsubscribing in the module "Email marketing"; a Yes/No order property of the checkout | server, with `identify` |
| `order_completed` | the order is placed (any checkout, the admin, the API) | server |
| `order_paid`, `order_refunded`, `order_cancelled` | the order becomes paid, a payment is returned, the order is cancelled | server, once per order event |
| `order_shipped` — delivery service, tracking number, tracking link, products of the parcel | a shipment is handed over to delivery: deducted ("Shipped") or in the status "Shipped" | server, once per shipment (a second parcel is a new event) |
| `order_delivered` | the order comes to a delivered status ("Completed" by default, the setting "Order statuses “Delivered”") | server, once per order |
| `add_to_cart`, `remove_from_cart`, `checkout_started` | cart changes and the checkout page (sale.order.ajax), for visitors with the tracker cookie or signed in | server |
| `add_to_wishlist`, `remove_from_wishlist` | a cart line is deferred ("Delayed", the wishlist of Bitrix), moved back to the cart or deleted while deferred | server |
| `product_viewed` | the product page: the view counter of the standard catalog.element templates | server, works with the composite cache |
| Page views | the storefront | browser tracker |
| Product catalog | product, price and stock changes (orders, cancellations, the admin, store documents), daily, and the "Upload the whole catalog" button | server, `products.write` |
| `search` | the first page of the catalog search results, with "Product search" on | server |

Every Bitrix order has a user (the guest checkout registers one), so orders, the cart and the account land in one profile; the browser of the checkout is saved with the order, so its later events (payment, refund) use it too.

## Installation

1. Download `onetrace.connector.zip` from the [latest release](https://github.com/OneTracePro/onetrace-bitrix/releases) and unpack it into `/local/modules/` (the folder `onetrace.connector`).
2. **Marketplace → Installed solutions**: install "OneTrace — customer data platform".
3. In your OneTrace project, open **API keys** and create a write key (for the storefront) and a secret key with the `products.write` permission (for the server); add "Send messages manually" (`messages.send`) for the store emails.
4. **Settings → Product settings → Module settings → OneTrace**: on the tab of each site enter the platform address (the domain you open OneTrace at) and both keys, click **Test connection**.
5. Run agents on cron (`agents_use_crontab`), otherwise the queue waits for visitors' hits.

Updating: unpack the new version over the folder and click **Save** in the settings once — this installs the new components and event handlers of the version (`/bitrix/components/onetrace/`).

## Shipments and delivery

The online chat of the platform answers "where is my order" from these events, so the tracking number matters most.

- **`order_shipped`** — for every shipment of the order except the system one, when it is deducted ("Shipped" in the order) or gets the shipment status "Shipped" (`DF`, or the status the "Online store" module sets on deduction). The event has the name of the delivery service as the customer sees it (none for "No delivery"), the tracking number, the tracking link when the delivery service has tracking (the Russian Post and delivery modules with a tracking class) and the products of the shipment. A second parcel of the order is a separate event; saving the shipment again or changing its tracking number gives none.
- A shipment handed over **without a tracking number** waits for it up to 12 hours in the queue: a number entered meanwhile (by hand or by the delivery module) goes with the event; after that the event goes without it, and a later number does not send it again.
- **`order_delivered`** — the order comes to one of the statuses of the setting **Order statuses “Delivered”** of the site (several with Ctrl; "Completed", `F`, by default; none — the event is not sent). Moving between these statuses gives no second event.

## Store emails

The emails of the store — orders, registration, password recovery, emails of the store modules — can go through the email integration of the OneTrace project: delivery statuses, history in the customer profile, your domain and its reputation. It is set up in OneTrace, section **Store emails** ("Send store emails through OneTrace" and the OneTrace templates of the order emails); the module reads these settings with the secret key (the "Send messages manually" permission) every 5 minutes and when the module settings are saved, and shows them read-only on the tab of each site.

- **Where:** `main:OnBeforeEventSend`, for every email template of a Bitrix mail event before Bitrix builds and sends it. The module builds the email the way Bitrix does (subject, body, From, Reply-To, CC, BCC, attachments of the template and of the event) and sends it to the platform, one request per recipient (5-second timeout, no retries, an idempotency key per event, template and recipient).
- **As is:** the subject, HTML (a text email becomes escaped HTML with clickable links; relative links of an HTML email become absolute), the text, the sender name, Reply-To, the language of the site and the attachments (PDF, PNG, JPEG, GIF, plain text, CSV, calendar; up to 5 files and 5 MB — anything else keeps the email with Bitrix).
- **Order templates:** an order email of a kind with an enabled OneTrace template goes to the customer as the order data — the number, the order page (the guest link when allowed, otherwise `/personal/order/detail/<number>/`), the lines with the picture, link, article and options, the totals, payment and delivery, the address of the order properties, the tracking of the shipment, the cancellation reason, the refund — and OneTrace builds the email in the language of the site.
- **Staff copies:** copies to the shop addresses (CC, BCC, the sender address of the site or of the main module, the order email of the "Online store", "Copies of all emails") never go through OneTrace, even with the relay on — the platform would make customer profiles of the staff addresses; they stay in the Bitrix email.
- **Fallback:** recipients the platform accepted leave the email; Bitrix sends the rest (and the staff copies) itself — no secret key or permission, the platform unavailable, the message quota (402), invalid data (422), too many emails to the recipient (429), an attachment it does not take. The rest has every address once: an address in To leaves CC and BCC (the BCC Bitrix adds for "Copies of all emails" too, through `main:OnBeforeMailSend`); with the customers gone, a staff copy becomes To. With everything sent the mail event is marked as sent.

| Bitrix mail event | Kind |
|---|---|
| `SALE_NEW_ORDER`, `SALE_NEW_ORDER_RECURRING` | `order_placed` |
| `SALE_ORDER_PAID` | `order_paid` |
| `SALE_ORDER_DELIVERY` ("delivery allowed"), `SALE_ORDER_TRACKING_NUMBER` | `order_shipped` (the shipment of the event) |
| `SALE_STATUS_CHANGED_<status>` | `order_delivered` for the statuses "Order statuses “Delivered”", otherwise `order_note` |
| `SALE_ORDER_CANCEL` | `order_cancelled` |
| `SALE_ORDER_REMIND_PAYMENT`, `SALE_ORDER_ALLOW_PAY`, `SALE_ORDER_SHIPMENT_STATUS_CHANGED` | `order_note` |
| `SALE_CHECK_PRINT` | `invoice` |
| `USER_PASS_REQUEST` | `password_reset` |
| `USER_INFO`, `USER_INVITE`, `NEW_USER` | `account_new` |
| `NEW_USER_CONFIRM`, `SUBSCRIBE_CONFIRM`, `SENDER_SUBSCRIBE_CONFIRM` | `email_verification` |
| `USER_CODE_REQUEST`, `CATALOG_PRODUCT_SUBSCRIBE_LIST_CONFIRM` | `login_code` |
| `USER_PASS_CHANGED`, `NEW_DEVICE_LOGIN`, other `SALE_*` and `CATALOG_*` | `other` |
| A copy to a shop address: CC, BCC, the sender address of the site or of the main module, the order email of the "Online store", "Copies of all emails" | never through OneTrace: Bitrix sends it (the platform would make customer profiles of the staff addresses) |
| Events of other modules (forum, blog, event log, feedback form …) | not touched |

Bitrix has no email for some order events; with their OneTrace template on, **the module sends them itself** from the queue (retries as for events), once each: `order_shipped` of a shipment handed over to delivery (after the tracking number or the 12-hour wait, as the event) when Bitrix did not send its own tracking number or "delivery allowed" email for it; `order_delivered` when the delivered status sends no Bitrix email (its "Notify" flag is off or the template is inactive); `order_refunded` for a returned payment.

## Catalog

The catalog is made of the products of the commercial catalog infoblocks bound to the site. Trade offers are not separate products: an offer uploads its parent, and a product with offers takes the lowest offer price. The price is what a guest pays with discounts; the old price — the price before discounts. Categories are the element sections with their parents; the brand — the property `BRAND`, `BRAND_REF` or `MANUFACTURER`; attributes — the properties of the smart filter.

The stock (`stock`) goes with a product that keeps count — quantity tracing is on and buying at zero ("Allow purchase when out of stock") is off, in the product or, for "default", in the settings of the "Commercial catalog" module; a product with offers sends the sum of its active offers in stock when every offer keeps count and none is sold at zero. Products without tracing or sold at zero go without it: zero stock does not mean "not available" for them. A change of the quantity — an order, its cancellation, the admin, a store document — uploads the product again. The platform uses the stock only when "Use stock levels from the API and plugins" is on in its catalog settings: then zero takes the product off sale and the "low stock" journey trigger works.

## Wishlist and "Notify me when available"

- **Wishlist.** Bitrix has no wishlist of its own; the deferred products of the cart ("Delayed", the "Delay" button of `sale.basket.basket`) are one: deferring a line sends `remove_from_cart` and `add_to_wishlist`, moving it back to the cart — `remove_from_wishlist` and `add_to_cart`, deleting a deferred line — `remove_from_wishlist`. The events feed the price drop and back in stock journeys. A theme with its own "Favorites" can mark its buttons for the tracker (1.13.0 and newer) instead: `data-cdp-wishlist="{product id}"` on "add" and `data-cdp-wishlist-remove="{product id}"` on "remove".
- **"Notify me when available".** Put the component in the template of the product page (`bitrix:catalog.element`, `template.php` of your template) next to the standard "Subscribe" button `bitrix:catalog.product.subscribe`:

  ```php
  $APPLICATION->IncludeComponent('onetrace:catalog.notify', '', ['PRODUCT_ID' => $arResult['ID']], $component, ['HIDE_ICONS' => 'Y']);
  ```

  For a product that cannot be bought it prints `<div data-cdp-notify="{product id}"></div>` (an offer gives its product), and the tracker draws the "Notify me when available" form of the project there (Site → Forms of the platform); the platform tells the customer when the product is back. The setting "Show the “Notify when available” form" turns it off per site. The standard subscribe button can then be removed: take its component out of the template or turn off the template parameter "Enable back-in-stock notifications" (`PRODUCT_SUBSCRIPTION`) of the catalog component. The component has no cache of its own: in a cached `catalog.element` it is cached with the product page, which Bitrix clears when the availability changes.

## Product search

Turn on **Product search** in the settings (the plan of the project must include it) and replace the component in the search page of the catalog — in the template of the complex component `bitrix:catalog` it is `search.php`:

```php
$APPLICATION->IncludeComponent('onetrace:catalog.search', '', $params…); // instead of 'bitrix:catalog.search'
```

- The parameters stay the same. The products, their order and their number come from OneTrace — word forms, typos, a query typed in the wrong keyboard layout, the visitor's interests — and are shown by `catalog.section` of your store. Sorting by price goes to the platform.
- A query with a redirect rule (Site → Search of the platform, e.g. "delivery" → the delivery page) opens the rule's page.
- The search fields of the store (`search.title` and forms with `q`) show suggestions of the tracker while typing instead of the title search popup.
- Any error of the platform keeps `bitrix:catalog.search`.

## Languages of the catalog

The catalog of a project comes from its first site. Other sites connected to the same project (the same address and secret key) with another language have their own copies of the catalog — as the exchange with 1C makes them — and the products and sections with the same `XML_ID` add translations: names, links on that site and the values of the attributes. The platform shows recommendations, widgets, search results and emails in the visitor's language.

## Bonus points

Turn on **Bonus points** in the settings (the loyalty program of the project must be active; the secret key needs the `loyalty.read` and `loyalty.write` scopes) and choose how the points go into the order:

- **Payment with points** — the prices stay as they are; the order gets a second, paid payment of the module's pay system "Bonus points" ("Оплата баллами" on Russian sites; inactive, customers do not choose it), and the checkout total shows "Paid" and "Left to pay". Refunds and accounting see the points as a payment.
- **Discount on the order lines** — the points lower the prices of the lines in proportion; the checkout shows the saving. Use it when the store's payment flow expects one payment per order.

The checkout (`sale.order.ajax`, any template) shows a block above the order total: the balance, the most points the order allows (the share is set in the program) and the points the order will earn. A signed-in customer applies the points, a guest sees a link to sign in. The points are held for 30 minutes and held again if the cart changes; placing the order confirms them with the order number, and failed confirmations are retried by the agent. The amount of `order_completed` is what is paid with money.

Customers see their points on a page of the personal section with the component:

```php
$APPLICATION->IncludeComponent('onetrace:loyalty.account', '', []);
```

— the balance, the points that become available after the return period, the history, and the "Join" button for those who are not members yet.

## Promo codes

Pools of promo codes are set up in the **Promo codes** section of the platform; the discount is a cart rule of the store (**Store → Marketing → Cart rules**, with coupons). Bind the rule to the pool in the setting "Promo codes" of the site, one per line:

```
welcome = 12
```

Every 5 minutes (agent) new and changed unique codes of the pool become one-order coupons of the rule with the expiry of the code. Coupons of other rules with the same code are not touched; pools with one shared code are not synced — add that coupon to a rule yourself. The order events carry the coupon, so the platform counts the use. The secret key needs `promo.read`.

**Referrals** (with bonus points on): a new user with the tracker's `cdp_ref` cookie of a `?ref=` link — the friend's code goes to the platform, and the friend gets the welcome reward; the member gets points for the friend's first order. The component `onetrace:loyalty.account` shows the referral link and the active promo codes of the customer.

## AI chat bot: store MCP

The AI bot of the OneTrace chat can answer customers with live data of the store and, if you allow it, act on orders. Turn on **Connect to the chat bot** in the "AI chat bot: store MCP" block of the site in the module settings and save: the module connects its MCP server (`/bitrix/services/onetrace.connector/mcp.php?site=s1`, store MCP contract v1, HTTPS) to the bot of the project. Sites of one project share one connection. The secret key needs the `chats.manage` permission.

- **Reading** (on by default): orders of the customer (the account and the other accounts with its email — a checkout without login makes one), an order with lines, payment, delivery service, address and the tracking number with its link, stock and prices of the catalog with offers, active delivery services (the price and the period of configurable ones), the return policy text and page of the settings.
- **Actions** (off by default, allow them in "What the bot may do"): cancel an order with nothing paid and nothing shipped (as the personal section allows), pass a return or delivery change request to the shop — the manager comment of the order and the mail event `ONETRACE_STORE_MCP_REQUEST` to the order email of the shop. The bot acts only after the customer agreed in the chat.
- **Customers**: signed-in customers are confirmed automatically — the login sets the `onetrace_chat` cookie, and the tracker script gets the signed token from `chat-customer.php` (the token never goes into a page of the composite cache); guests and messenger customers get a 6-digit code by the mail event `ONETRACE_STORE_MCP_CODE` (a handler of `onetrace.connector:onStoreMcpSendCode` returning a success result sends it itself, e.g. by SMS). Orders of other customers are "not found".

## Recommendations and Web Push

- Widgets from **Site → Widgets** of the platform: place the component **OneTrace recommendations** (`onetrace:recommendations`) in the product page, the cart or any page and set the widget id; pass the product ids of the page (`ITEMS`) and the section (`CATEGORY`) where the widget type needs them.
- Web Push: turn it on in the settings — the module serves `/cdp-sw.js` from the site root (a rule of `urlrewrite.php`).

## Privacy

- Consents come from the store's own subscriptions; a checkout checkbox counts when you set its order property code in the settings.
- Logout forgets the tracker visitor of the browser, so the next person at this computer starts a new profile.
- Data already sent stays in the OneTrace project; delete profiles there (GDPR).

## Development

```bash
composer install
tests/env/run.sh                       # integration tests: Bitrix "Small business" in Docker, PHP 8.3
PHP_VERSION=8.2 tests/env/run.sh       # another PHP (tests/env: docker compose down -v first)
tests/env/smoke.sh                     # the storefront over HTTP
php bin/build.php                      # dist/onetrace.connector.zip and dist/marketplace.zip
```

The tests download the trial distribution of "Small business" (the latest version), pass the installation wizard without a browser (`tests/env/wizard.py`, adapted from [BitrixBox](https://github.com/razzhivine/bitrixbox), MIT) and install the module from the working copy: orders go through the sale API (basket, shipment, payment), subscriptions through the "Email marketing" module.

## License

MIT
