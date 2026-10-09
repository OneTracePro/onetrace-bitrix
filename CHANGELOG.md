# Changelog

All notable changes are documented here. The project follows [Semantic Versioning](https://semver.org/).

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
