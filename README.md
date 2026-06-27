<!-- markdownlint-disable MD041 -->
<p align="center">
  <strong>NDV Reviews</strong><br>
  <em>Reliable, self-hosted product reviews for WooCommerce.</em>
</p>

<p align="center">
  <a href="#features">Features</a> ·
  <a href="#installation">Installation</a> ·
  <a href="#development">Development</a> ·
  <a href="#documentation">Docs</a> ·
  <a href="#license">License</a>
</p>

---

**NDV Reviews** is a faster, privacy-first WooCommerce reviews plugin — a genuinely useful free version on WordPress.org plus a Pro add-on for automation, AI, and extra channels. A [Nowdigiverse](https://nowdigiverse.com) product.

Built as a better alternative to ReviewX and WiserReview:

- 🔒 **Self-hosted, no account required** — the free plugin works with zero external calls. Your data stays in your database.
- ♾️ **No metering** — unlimited reviews, requests, and storage. It's your server.
- 📬 **Reliable review reminders** — background queue (Action Scheduler) with a visible log, retry, and test-send.
- ✏️ **Honest free tier** — full moderation, including editing reviews, is free.
- ⭐ **Multi-criteria ratings, photo reviews, verified badges, rich schema.**

## Features

See the [Free vs Pro breakdown](docs/content/free-vs-pro.md). The free plugin ships multi-criteria ratings, photo reviews, reliable email reminders, moderation, schema, shortcodes/blocks/Elementor, importers/exporter, and GDPR tools. Pro adds video, automation (SMS/WhatsApp), AI, Q&A, the full widget catalog, ESP connectors, analytics, and more.

## Repository layout

```
ndv-reviews/            FREE plugin (this repo) — WordPress.org-bound
  ndv-reviews.php       Bootstrap, constants, activation
  includes/             PSR-4 (NdvReviews\) classes
  assets/               Blocks + admin + frontend sources
  templates/            Theme-overridable templates
  docs/                 Documentation site (HTML/CSS/JS + Markdown)
  languages/            i18n .pot
ndv-reviews-pro/        PRO add-on (separate private repo) — license-gated
```

## Installation

1. Copy the `ndv-reviews` folder into `wp-content/plugins/`.
2. Activate it from the Plugins screen (WooCommerce must be active).

No `composer install` is required — the plugin ships a runtime PSR-4 autoloader. Composer/npm are only needed for development tooling.

## Development

Requirements: PHP 7.4+, Node 18+, (optional) Composer.

```bash
# PHP coding standards (WordPress + WooCommerce)
composer install
composer lint          # phpcs
composer lint:fix      # phpcbf

# JS/CSS + blocks build
npm install
npm run build          # production build
npm run start          # watch mode
npm run lint:js
npm run makepot        # regenerate languages/ndv-reviews.pot
```

The build is gated on **Plugin Check (PCP) = 0 errors** and **PHPCS = 0 errors** before every release.

## Documentation

Open `docs/index.html` (served over HTTP — browsers block `fetch` on `file://`). The docs are also plain Markdown under [`docs/content/`](docs/content/) so they stay readable by humans and AI tools alike, and are updated **phase by phase** alongside development.

- [Overview](docs/content/overview.md)
- [Architecture](docs/content/architecture.md)
- [Data Model](docs/content/data-model.md)
- [Hooks & Filters](docs/content/hooks.md)
- [Build Phases](docs/content/phase-0.md)

The full build specification lives in [`build-plan.md`](build-plan.md).

## License

GPLv2 or later. © Nowdigiverse.
