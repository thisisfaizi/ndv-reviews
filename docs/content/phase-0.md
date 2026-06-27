# Phase 0 — Scaffolding {badge:done:COMPLETE}

> **Goal (build-plan §15):** Bootstrap, constants, PSR-4 autoload, activation/deactivation, `dbDelta` schema, HPOS + block-checkout declarations, settings option scaffold, build pipeline, lint/CI config.
> **Done when:** plugin activates cleanly, tables created, Plugin Check passes.

## What was built

### Bootstrap & constants
- `ndv-reviews.php` — plugin header (GPLv2-or-later, `Requires Plugins: woocommerce`, text domain `ndv-reviews`), central `NDVR_*` constants (single source of truth, build-plan §13), autoloader registration, activation/deactivation hooks, WooCommerce feature declarations, and graceful boot.

### Autoloading & container
- `includes/Support/Autoloader.php` — dependency-free PSR-4 autoloader (`NdvReviews\` → `includes/`). Works without `composer install`; defers to `vendor/autoload.php` if present.
- `includes/Support/Container.php` — lazy-singleton service container.
- `includes/Support/Registerable.php` — interface for self-registering service modules.
- `includes/Support/Settings.php` — cached accessor over the single `ndv_reviews_settings` option (avoids option sprawl, build-plan §6.3).
- `includes/Plugin.php` — orchestrator. Boots i18n, registers services, exposes the `ndv-reviews/loaded` and `ndv-reviews/services` extension points, and renders the WooCommerce-missing notice.

### Database
- `includes/Installer.php` — `dbDelta` schema for **all 12 custom tables** (criteria, review_criteria, review_media, review_votes, requests, questions, answers, ai_meta, forms, connections, campaigns, review_tokens). Stores `ndv_reviews_db_version` and upgrades on version bump via `admin_init`.
- `includes/Activator.php` / `includes/Deactivator.php` — create tables + seed defaults on activate; non-destructive deactivate.
- `uninstall.php` — drops tables and options **only** when the "remove data on uninstall" setting is enabled (default: keep data).

### Compliance & tooling
- `readme.txt` — WordPress.org format; describes WooCommerce compatibility in the body (not the name/slug).
- `phpcs.xml.dist` — `WordPress` + `WordPress-Extra` + `PHPCompatibilityWP` (7.4+), text-domain and prefix enforcement.
- `composer.json` (PSR-4 + dev tooling), `package.json` (`@wordpress/scripts` build), `.editorconfig`, `.gitignore`.
- `languages/ndv-reviews.pot` — i18n template scaffold.

## Decisions logged

- **All 12 tables created up front** so later phases need migrations only for schema *changes*, not new tables.
- **Composer optional at runtime** — environment has no Composer; the custom autoloader keeps the plugin installable as a plain zip.
- **WooCommerce-missing is non-fatal** — admin notice + bail, never a white screen.

## Acceptance criteria

- ☑ All PHP files lint clean (`php -l`).
- ☑ Plugin boots through the autoloader (WordPress loads it without Composer).
- ☐ Plugin activates with no fatals/notices — *verify in wp-admin once the Local site DB is running.*
- ☐ `SHOW TABLES LIKE '%ndvr_%'` returns all 12 tables — *verify after activation.*
- ☐ Plugin Check (PCP) = 0 errors — *install Plugin Check and run in-site.*

> The last three are runtime checks. They require the Local site's MySQL service to be started; the code is complete and ready to verify.

## Next

**Phase 1 — Core reviews (free):** review form (photo, consent, anti-spam), multi-criteria (≤3), verified badge, store as comment + custom tables, overall rating cache.
