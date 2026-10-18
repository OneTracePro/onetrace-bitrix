# Changelog

All notable changes are documented here. The project follows [Semantic Versioning](https://semver.org/).

## 1.4.0 — 2026-10-18

- Stock: catalog items carry `stock` when the product keeps count — quantity tracing on and buying at zero off (the product's settings or the defaults of the "Commercial catalog" module); a product with offers — the sum of its active offers in stock when every offer keeps count and none is sold at zero. Products without tracing or sold at zero go without it. Orders, cancellations, the admin and store documents change the quantity through the catalog model, so the product is uploaded again as before. The platform uses the stock only with "Use stock levels from the API and plugins" on.
- Wishlist: the deferred products of the cart ("Delayed") send `add_to_wishlist` and `remove_from_wishlist`; deferring a line takes it out of the cart (`remove_from_cart`), moving it back adds it again, a new deferred line is not an `add_to_cart` any more. Themes with their own "Favorites" can use `data-cdp-wishlist` / `data-cdp-wishlist-remove` of the tracker.
- The component onetrace:catalog.notify: the place of the platform's "Notify me when available" form (`<div data-cdp-notify>`) on the page of a product that cannot be bought; the setting "Show the “Notify when available” form" (on by default) per site.
- Saving the settings installs the components of the new version after an update; the removal deletes the component onetrace:loyalty.account too.
- onetrace-php 1.10.

## 1.3.0 — 2026-10-13

- Promo codes: the "Promo codes" setting of a site binds a pool of OneTrace promo codes to a cart rule ("pool_key = rule id" per line). Every 5 minutes (agent) new and changed codes of the pool come by the cursor of the platform and become one-order coupons of the rule with the expiry of the code; coupons of other rules with the same code are left as they are. The connection check warns about a missing promo.read permission.
- Referrals: a new user with the tracker's `cdp_ref` cookie of a `?ref=` link — the friend's code is sent to the platform by an agent (`loyalty()->refer()`), repeated while the user is unknown to it; the friend gets the welcome reward at once.
- The component onetrace:loyalty.account: the referral link with a copy button, friends who came and the points for them, active promo codes of the customer; referral rewards in the history.
- onetrace-php 1.7.

## 1.2.0 — 2026-10-13

- Bonus points of the OneTrace loyalty program (the "Bonus points" setting, off by default) with a choice per site: a paid payment "Оплата баллами" (prices unchanged, "Paid" and "Left to pay" in the checkout total) or a discount of the order lines.
- The block above the order total of sale.order.ajax: the balance, the most points the order allows, the points it earns; applying and removing points; a sign-in link for guests. The points are held for 30 minutes, held again when the cart changes and confirmed with the order number when the order is placed; the agent retries failed confirmations.
- The component onetrace:loyalty.account for the personal section: the balance, pending points, the history and joining the program.
- The amount of `order_completed` excludes the points payment.
- The settings page no longer prints the stored secret key: an empty field keeps it.
- onetrace-php 1.6.

## 1.1.0 — 2026-10-10

- Product search from OneTrace (the "Product search" setting, off by default): the component onetrace:catalog.search replaces bitrix:catalog.search with the same parameters — the products, their order and their number come from the platform (word forms, typos, the wrong keyboard layout, the visitor's interests) and are shown by catalog.section of the store, with sorting by price and pages over the number found; a query with a redirect rule opens its page; the results page sends the `search` event. Any API error keeps the usual search.
- Suggestions while typing in the search fields of the store (the tracker instead of the title search popup); a category leads to its section page.
- Catalog languages: sites of one project in other languages with their copies of the catalog (the same XML_ID, as the exchange with 1C makes it) add translations of product names, links and attributes and of section names; a changed copy uploads the main product.

## 1.0.0 — 2026-10-10

First release.

- Orders from the server (module "sale"): placed (any checkout, the admin, the API), paid, payments returned and cancelled, with deterministic message ids; later order events use the browser of the checkout.
- Customers: identify on registration, login, profile changes and with every order; logout forgets the tracker visitor of the browser.
- Newsletter consents from the module "Email marketing" (subscribing and unsubscribing) and a Yes/No order property of the checkout.
- Cart events from the server: add_to_cart, remove_from_cart, checkout_started (sale.order.ajax, once per cart content).
- product_viewed from the view counter of the standard catalog.element templates — no template changes, works with the composite cache.
- The website tracker in <head>, cached with the page.
- Product catalog sync: on element, price and stock changes, daily and on demand; offers upload their product with the lowest offer price; sections with parents, brands and the smart filter properties.
- The component "OneTrace recommendations" and Web Push (/cdp-sw.js through urlrewrite).
- Settings per site with the connection check and the queue state; the queue with retries in an agent; sending pauses on a rejected key until the settings are saved; English and Russian.
- The onetrace-php library bundled under the module namespace; archives for /local/modules and the Bitrix Marketplace.
