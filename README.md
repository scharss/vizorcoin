# VizorCoin

Display WooCommerce prices in a chosen currency (USD, EUR, CNY, ...) **without changing the store's real currency**. The order, the payment gateway, receipts, and emails always stay in the store currency (e.g. COP); VizorCoin only changes how prices are *shown* to the customer.

- **Author:** Scharss Azael ([https://byterock.xyz/](https://byterock.xyz/))
- **License:** GPL-2.0-or-later
- **Requires:** WordPress 5.8+, PHP 7.4+, WooCommerce 6.0+
- **Tested up to:** WooCommerce 9.8, WordPress 6.x

---

## What it does

The store runs in its base currency (configured in **WooCommerce → Settings**, e.g. COP). VizorCoin lets you pick a **display currency** that customers see in the catalogue and product pages, converted live from the base price using open exchange-rate APIs.

Key guarantees:

- **The base currency is never modified.** Cart totals, the order, the payment gateway, the confirmation page, admin and emails are processed in the store currency.
- Conversion is **presentation only**. The amount stored/charged is the base amount.
- Before the customer is sent to the payment gateway, a modal explains that the charge will be processed in the store currency and shows both amounts.

## Features

- Admin menu **VizorCoin** (top-level) with all settings.
- Exchange rates from **Yahoo Finance** (primary) with **open.er-api.com** fallback, plus an optional **manual rate**.
- Rate **caching** (configurable TTL) and automatic precision handling.
- Converts prices on:
  - Shop, categories, tags and single product pages.
  - Cart and checkout (classic).
  - **CartFlows** checkout (classic).
  - **Modern Cart** slide-out / floating cart (server-rendered and AJAX).
  - Order confirmation / Thank-You page (amounts and currency badge).
- **Confirmation modal** on the checkout page before proceeding to the gateway.
- Whole-number formatting (integers) and configurable scope.
- HPOS (High-Performance Order Storage) and Cart/Checkout blocks compatibility declared.

## Installation

1. Copy the `vizorcoin` folder into `wp-content/plugins/`.
2. Activate **VizorCoin** in **Plugins**.
3. Go to **VizorCoin** in the admin menu, choose the display currency and save.

## Configuration

| Option | Description |
| --- | --- |
| **Enable visual conversion** | Turns the display conversion on/off. |
| **Display currency** | The currency customers see (must differ from the store currency). |
| **Rate source** | `Auto` (Yahoo + er-api), `Yahoo Finance` only, or `open.er-api.com` only. |
| **Cache (minutes)** | How long a fetched rate is reused. |
| **Manual rate (optional)** | If set, it is used and the APIs are ignored. Value = units of display currency per 1 base unit. |
| **Conversion scope** | When enabled, also converts outside the catalogue (home and other pages with products). |
| **Cart and checkout** | Show cart and checkout in the display currency (payment still in store currency). |
| **Modal** | Enable/title/text of the confirmation modal (`{base_currency}` and `{display_currency}` placeholders supported). |

### How to read the rate status

The settings screen shows the last fetched rate, its timestamp and the source used. Use **Refresh rate now** to clear the cache and fetch immediately.

## How it works

- **Conversion:** the `wc_price` filter is used to re-render the formatted price in the display currency. No product, cart or order values are modified, so gateways keep charging the base currency.
- **Rates:** `VizorCoin_Rates` fetches `1 base = rate display`. Yahoo is queried first (using the pair with the highest precision and inverting when needed), then `open.er-api.com`. Results are cached in a transient; a manual rate overrides everything; the last known rate is reused if the network fails.
- **Modal:** the real base total is emitted in a hidden element (`#vizorcoin-base-total`) inside the order review, so the modal reads it instantly (no extra request) and shows the exact charge plus its display equivalent.
- **Contexts:** conversion is skipped in admin, REST/Store API, cron, CLI, autosave, emails, and `order-pay`. It is applied on the catalogue, cart, checkout (including CartFlows and Modern Cart), and the order confirmation.

## Notes and FAQ

- **Payments are always in the store currency.** The modal makes this explicit.
- **Order confirmation, admin and emails stay in the store currency.** Only the on-screen display is converted.
- **Block checkout** (`/finalizar-compra/` when it uses the Checkout block) is not converted, because it renders prices through the Store API in JavaScript. The classic/CartFlows/Modern Cart experiences are converted.
- If prices look stale or show odd decimals, hard-refresh the browser (Ctrl+F5); the script uses a version query for cache-busting.
- The plugin requires writable pretty permalinks (a working `.htaccess`) for the shop, cart and checkout URLs.

## Changelog

### 1.0.1
- Integer price formatting in the confirmation modal.
- Convert the order confirmation / Thank-You page (amounts and currency badge).

### 1.0.0
- Initial release: admin settings, Yahoo + er-api rates with caching and manual override, catalogue/cart/checkout display conversion (CartFlows and Modern Cart), and the pre-gateway confirmation modal.

---

© Scharss Azael — [https://byterock.xyz/](https://byterock.xyz/)
