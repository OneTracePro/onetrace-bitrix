# OneTrace.pro for 1C-Bitrix

[![CI](https://github.com/OneTracePro/onetrace-bitrix/actions/workflows/ci.yml/badge.svg)](https://github.com/OneTracePro/onetrace-bitrix/actions/workflows/ci.yml)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

Connects a 1C-Bitrix "Site management" store to [OneTrace.pro](https://onetrace.pro), the customer data platform: orders, customers and newsletter consents from the server, the website tracker, product views, the product catalog, recommendation widgets and Web Push. Abandoned cart, browse abandonment, post-purchase and win-back journeys, product recommendations and predictions work right after you connect the store.

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
| `add_to_cart`, `remove_from_cart`, `checkout_started` | cart changes and the checkout page (sale.order.ajax), for visitors with the tracker cookie or signed in | server |
| `product_viewed` | the product page: the view counter of the standard catalog.element templates | server, works with the composite cache |
| Page views | the storefront | browser tracker |
| Product catalog | product, price and stock changes, daily, and the "Upload the whole catalog" button | server, `products.write` |
| `search` | the first page of the catalog search results, with "Product search" on | server |

Every Bitrix order has a user (the guest checkout registers one), so orders, the cart and the account land in one profile; the browser of the checkout is saved with the order, so its later events (payment, refund) use it too.

## Installation

1. Download `onetrace.connector.zip` from the [latest release](https://github.com/OneTracePro/onetrace-bitrix/releases) and unpack it into `/local/modules/` (the folder `onetrace.connector`).
2. **Marketplace → Installed solutions**: install "OneTrace — customer data platform".
3. In your OneTrace project, open **API keys** and create a write key (for the storefront) and a secret key with the `products.write` permission (for the server).
4. **Settings → Product settings → Module settings → OneTrace**: on the tab of each site enter the platform address (the domain you open OneTrace at) and both keys, click **Test connection**.
5. Run agents on cron (`agents_use_crontab`), otherwise the queue waits for visitors' hits.

## Catalog

The catalog is made of the products of the commercial catalog infoblocks bound to the site. Trade offers are not separate products: an offer uploads its parent, and a product with offers takes the lowest offer price. The price is what a guest pays with discounts; the old price — the price before discounts. Categories are the element sections with their parents; the brand — the property `BRAND`, `BRAND_REF` or `MANUFACTURER`; attributes — the properties of the smart filter.

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
