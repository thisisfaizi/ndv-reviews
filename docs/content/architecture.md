# Architecture

## Stack & targets

- **PHP** 7.4 → 8.3+ (typed properties used cautiously for 7.4 compat).
- **WordPress** 6.0 → latest. **WooCommerce** 7.0 → latest.
- **HPOS** (High-Performance Order Storage) compatible — never query `wp_posts` for orders; use Woo CRUD APIs.
- **Cart/Checkout Blocks** compatible.
- **CSS/JS** scoped and prefixed `ndvr-`; no jQuery dependency in new code.
- **Build:** `@wordpress/scripts` for blocks/admin; PSR-4 autoload.

## Bootstrap flow

1. `ndv-reviews.php` defines the central constants (`NDVR_*`), registers the autoloader, and hooks activation/deactivation at the top level.
2. WooCommerce feature compatibility (`custom_order_tables`, `cart_checkout_blocks`) is declared on `before_woocommerce_init`.
3. On `plugins_loaded`, if WooCommerce is active, `NdvReviews\Plugin::instance()->boot()` runs. Otherwise a dismissible admin notice is shown and the plugin bails — **no fatal**.
4. `boot()` loads i18n, registers service modules, and fires `do_action( 'ndv-reviews/loaded', $plugin )` — the entry point the Pro add-on hooks into.

## Autoloading without Composer

Composer is not required at runtime. A lightweight PSR-4 autoloader (`includes/Support/Autoloader.php`) maps `NdvReviews\` → `includes/`, mirroring the `composer.json` `autoload.psr-4` map. If `vendor/autoload.php` exists, it takes precedence.

## The service container

`includes/Support/Container.php` is a tiny lazy-singleton container. Core services are bound in `Plugin::register_core_services()`. The Pro add-on registers its own services through the same container via the `ndv-reviews/loaded` hook — keeping the free↔Pro boundary a clean, documented API.

## Naming (single source of truth)

| Concern | Value |
|---|---|
| Text domain | `ndv-reviews` |
| PHP namespace | `NdvReviews\` |
| Function/hook prefix | `ndv_reviews_` / `ndvr_` |
| CSS/JS class prefix | `ndvr-` |
| DB table prefix | `{$wpdb->prefix}ndvr_` |
| Settings option | `ndv_reviews_settings` |

Renaming the brand = change the `NDVR_*` constants, the slug folder, and the readme.

## WordPress.org compliance (hard gate)

Every PHP file starts with `defined( 'ABSPATH' ) || exit;`. All output is escaped, all input sanitized, every write nonce- and capability-checked, every query prepared. No remote-loaded executable code in the free plugin, no tracking by default, and "WooCommerce"/"WordPress" never appear in the plugin name or slug. Plugin Check (PCP) and PHPCS (`WordPress` standard) gate every phase.
