# Changelog

All notable changes are documented here. The project follows [Semantic Versioning](https://semver.org/).

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
