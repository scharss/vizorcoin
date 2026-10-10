# AGENTS.md

Guidance for AI agents and developers working on the **VizorCoin** WooCommerce plugin.

Author: Scharss Azael — https://byterock.xyz/ · License: GPL-2.0-or-later

## Purpose

VizorCoin displays WooCommerce prices in a chosen currency (USD, EUR, CNY, ...) as a **presentation-only** conversion. It must **never** change the store's real currency, cart totals, order totals, or what the payment gateway charges. The store base currency (e.g. COP) is authoritative for all data and payments.

## File map

```
vizorcoin/
├── vizorcoin.php                       # Plugin header, bootstrap, HPOS/blocks compatibility
├── includes/
│   ├── class-vizorcoin.php             # Singleton orchestrator, default settings, currency helpers
│   ├── class-vizorcoin-settings.php    # Admin menu, Settings API, sanitization, rate refresh
│   ├── class-vizorcoin-rates.php       # Yahoo + er-api fetch, cache (transients), manual override
│   ├── class-vizorcoin-frontend.php    # wc_price conversion + context detection (should_convert)
│   └── class-vizorcoin-checkout.php    # Enqueues modal + thank-you assets, base-total hidden element
├── assets/
│   ├── css/vizorcoin.css               # Modal styling
│   ├── js/vizorcoin-checkout.js        # Place-order interception + confirmation modal
│   └── js/vizorcoin-thankyou.js        # Swaps the currency badge on the confirmation page
├── uninstall.php                       # Removes options/transients
├── README.md
└── AGENTS.md
```

## Core concepts

- **Base currency** = `get_option('woocommerce_currency')`. **Display currency** = the VizorCoin setting.
- **Rate** is stored as *units of display currency per 1 base unit*.
- The plugin is "active" when enabled and `display_currency !== base_currency` (`VizorCoin::is_active()`).

### Class responsibilities

- `VizorCoin` — singleton (`VizorCoin::instance()`), defaults (`defaults()`), `get_settings()`, `base_currency()`, `display_currency()`, `is_active()`, `get_rate()`, `convert()`.
- `VizorCoin_Rates` — `get_rate($base, $target, $force)`; tries manual → transient cache → Yahoo → er-api → last known rate. Keeps a key list for `clear_cache()`.
- `VizorCoin_Frontend` — hooks `wc_price` (priority 100). `should_convert()` decides the context; `filter_wc_price()` re-renders the price; `normalize_amount()` handles prices that arrive already formatted (e.g. `"150,000.00"`).
- `VizorCoin_Checkout` — enqueues the modal on checkout and the badge script on the confirmation page; prints `#vizorcoin-base-total` via `woocommerce_review_order_after_order_total`.

## Conversion flow (`VizorCoin_Frontend`)

1. `should_convert()` gates by context (see below).
2. `filter_wc_price()` skips if already converting (recursion guard), if `$args['currency']` is a different currency, or if no rate.
3. Amount is normalized, multiplied by the rate, and re-rendered with `wc_price(..., ['currency' => $target, 'decimals' => 0])`.

### Where conversion applies (`should_convert`)

- **Catalog/Product:** `is_woocommerce()` / `is_shop()` / `is_product()` / taxonomies, unless the *broaden* option extends it to home/other pages.
- **Cart/Checkout:** `is_cart()` / `is_checkout()`, plus AJAX refreshes:
  - `wc-ajax=update_order_review` (WooCommerce),
  - `_is_wcf_doing_checkout_ajax()` (CartFlows).
- **Modern Cart drawer:** detected with `doing_action('moderncart_slide_out_content')` (covers both the `wp_footer` render and every `moderncart_*` AJAX refresh, all of which render `slide-out-inner.php`). This check runs **before** `is_admin()`, because admin-ajax sets `is_admin()` to true.
- **Order confirmation:** `is_wc_endpoint_url('order-received')` and `_is_wcf_thankyou_type()` (CartFlows Thank-You).

Never convert in: admin, REST/Store API, cron, WP-CLI, autosave, WooCommerce emails, or `order-pay`.

## Checkout modal

- `#vizorcoin-base-total` (hidden, printed by `render_base_total()`) holds the raw base total (`WC()->cart->get_total('edit')`) and is refreshed as part of the order-review AJAX fragment.
- `vizorcoin-checkout.js` intercepts the "Place order" click in the capture phase, reads the base total synchronously (hidden element → `wp.data` store → DOM), shows the modal, and on accept re-dispatches the click.

## Thank-You page

- The CartFlows Instant Thank-You uses `wc_price(...)` with the order currency (base), so amounts convert automatically once the context is allowed.
- The currency badge (`.wcf-ic-cart-totals__currency-badge`) is printed with `$order->get_currency()`; `vizorcoin-thankyou.js` replaces its text with the display currency.

## Settings option

All settings live in the single option `vizorcoin_settings` (see `VizorCoin::defaults()`). Managed by `VizorCoin_Settings` via the Settings API (`vizorcoin_group`).

## Filters

- `vizorcoin_should_convert` — override whether conversion applies in the current context.

## Conventions

- WordPress PHP coding standards (tabs, YAML docblocks, `esc_*`/`sanitize_*`, nonces via Settings API).
- Text domain: `vizorcoin`.
- Do **not** add comments unless they add real value; keep Spanish admin strings consistent with the rest of the plugin.
- Prefix classes with `VizorCoin_` and use the `VIZORCOIN_` constant prefix.

## Gotchas

- **`wp_localize_script()` casts every scalar to string** (`class-wp-scripts.php`). `decimals => 0` arrives in JS as `"0"`. Always coerce numbers in JS (`parseInt` / `parseFloat`) — see `formatNumber()`.
- **`is_admin()` is `true` inside `admin-ajax.php`.** Frontend AJAX handlers (e.g. Modern Cart) must be detected before the `is_admin()` early return.
- **Block checkout** (`/finalizar-compra/` with the Checkout block) renders prices via the Store API in JS and is **not** converted.
- `wc_price` may receive an **already formatted** price string; `normalize_amount()` strips the store's thousand/decimal separators before converting.
- Bump `VIZORCOIN_VERSION` (and the plugin header `Version:`) whenever JS/CSS changes so browsers fetch the new assets.

## Verification

Run from the plugin root when the code is inside the Docker container at `/var/www/html/wordpress/wp-content/plugins/vizorcoin`:

```bash
# PHP syntax
php -l vizorcoin.php
php -l includes/class-vizorcoin.php
php -l includes/class-vizorcoin-settings.php
php -l includes/class-vizorcoin-rates.php
php -l includes/class-vizorcoin-frontend.php
php -l includes/class-vizorcoin-checkout.php
php -l uninstall.php

# JavaScript syntax (requires Node)
node --check assets/js/vizorcoin-checkout.js
node --check assets/js/vizorcoin-thankyou.js
```

Manual smoke test: with an item in the cart, confirm the catalogue, cart page, checkout (CartFlows), Modern Cart drawer and the order confirmation show the display currency, while the order and gateway stay in the store currency.
