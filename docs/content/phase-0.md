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

## Plugin Check resolution

The first Plugin Check run flagged dev-only files and two real readme issues. Fixes:

- **readme.txt** — `Tested up to` bumped to the current WordPress version; short description trimmed under 150 characters.
- **Plugin.php** — removed the discouraged `load_plugin_textdomain()` call (WordPress.org auto-loads translations for the plugin slug since 4.6).
- **Dev-file errors** (`.editorconfig`, `.gitattributes`, `phpcs.xml.dist`) and **planning-markdown warnings** (`build-plan.md`, `claude.md`) are resolved by **distribution exclusion**, not deletion — these files are needed for development (e.g. `phpcs.xml.dist` powers the CI lint gate) but must never ship in the plugin. `.distignore` + `.gitattributes` `export-ignore` strip them from the built zip.

**Run Plugin Check against the built plugin, not the dev tree:**

```
bash bin/build-zip.sh        # produces dist/ndv-reviews-x.y.z.zip
```

The clean build contains only `includes/`, `ndv-reviews.php`, `readme.txt`, `uninstall.php`, `languages/`, and `README.md` — zero hidden files, zero application files, zero unexpected markdown. CI also runs Plugin Check on this clean tree (`.github/workflows/plugin-check.yml`).

## Acceptance criteria

- ☑ All PHP files lint clean (`php -l`), PHP 7.4–8.3 syntax matrix in CI.
- ☑ Plugin boots through the autoloader (WordPress loads it without Composer).
- ☑ Plugin activates (Plugin Check ran in-site, which requires activation).
- ☑ Plugin Check on the **distributed build** = 0 errors (dev-tree-only flags excluded by `.distignore`).
- ☐ `SHOW TABLES LIKE '%ndvr_%'` returns all 12 tables — *confirm in the live DB.*

> Table creation is the one item to confirm directly in the database (dbDelta fails silently). The CLI cannot reach Local's MySQL socket; verify via Adminer/phpMyAdmin in Local, or wp-admin Site Health.

## Next

**Phase 1 — Core reviews (free):** review form (photo, consent, anti-spam), multi-criteria (≤3), verified badge, store as comment + custom tables, overall rating cache.
