# Phase 4 — Integrations, Importers, Privacy {badge:wip:CODE-COMPLETE}

> **Goal (build-plan §15):** Shortcodes + Gutenberg blocks + Elementor + classic widgets; CPT support; Woo-native + CSV import; CSV/JSON export; GDPR; performance pass; i18n — the free v1.0 surface.

## What was built

### Shared renderer (single source of truth)
- **Display/Widgets.php** — one renderer (stars, summary, criteria graph, reviews list, marquee, recent) reused by every surface so markup never diverges; conditional, idempotent asset enqueue.

### Shortcodes (`Integrations/Shortcodes.php`)
`[ndvr-reviews]`, `[ndvr-summary]`, `[ndvr-criteria-graph]`, `[ndvr-stars]`, `[ndvr-marquee]` — plus `[ndvr-testimonial]` / `[ndvr-form]` from the testimonial module. All accept `product_id`/`post_id`, so they work on **any post type** (CPT support).

### Gutenberg blocks (`Integrations/Blocks.php`)
Server-rendered (no build step): **NDV Reviews: Summary / Stars / Reviews / Reviews Marquee**, previewed in the editor via `wp.serverSideRender` with inspector controls.

### Classic widgets (`Integrations/Widgets/`)
`WP_Widget` **Summary** and **Marquee** for non-block, non-Elementor themes — sharing the same renderer.

### Elementor (`Integrations/Elementor/`, build-plan §20)
- Widget category **NDV Reviews** + widgets: **Star Rating, Review Summary, Review Section, Reviews Marquee**.
- **Dynamic tags** (Loop Item bindings): **Product Rating Value**, **Product Review Count** — both read **cached** meta (no per-card query → loop-safe).
- **Loop/theme-builder context resolution** with an **editor sample fallback** so widgets never appear empty while designing.

### Reviews Marquee (build-plan §22)
Magic UI-style infinite-scroll marquee, **reimplemented in vanilla CSS/JS** (no React/Tailwind): horizontal/vertical, speed/gap, pause-on-hover, gradient fade edges, `prefers-reduced-motion` aware. Shortcode + block + Elementor + classic widget from one renderer.

### Importers / Exporter (`Importers/`, Admin/ToolsPage)
- **Woo-native backfill** (idempotent): enriches existing WooCommerce reviews with our meta + aggregates.
- **CSV importer** (mapped columns).
- **Exporter** to CSV / JSON — no lock-in.

### Privacy / GDPR (`Privacy/Privacy.php`)
Registers with WordPress's **Personal Data Exporter and Eraser** (reviews, media, votes), and **consent is logged** (timestamp) on every submission.

### Standalone testimonial form (`Forms/TestimonialForm.php`, build-plan §19.2)
A shortcode/AJAX form to collect a review **without an order** (services/landing pages); submissions enter the same moderation queue flagged `source=form`.

## Acceptance criteria (§7.5, §7.18, §7.19, §9, §20, §22)

Status: **code-complete, lint-clean; storefront boot re-verified (0 console errors). Editor/admin/importer flows pending a user pass.**

- ☑ Plugin boots with all integrations wired — product page renders clean.
- ☐ Each shortcode **and** Gutenberg block renders; assets enqueue only where used.
- ☐ Elementor widgets appear under NDV Reviews; dynamic tags resolve per loop product; no duplicate AggregateRating in a loop grid.
- ☐ Classic widgets render in sidebars.
- ☐ Woo-native + CSV import map correctly and are idempotent; export round-trips.
- ☐ WP export/erase includes reviews/media/votes; consent recorded.

### Deferred to a 4.x follow-up (documented, not blocking)
- **QR-code** generation (needs a bundled GPL QR encoder) — the shareable/tokenized link already exists.
- **Manual topic filter pills** (the AI version is Pro, Phase 7).
- Additional classic widgets (Recent Reviews, Rating Badge, Top-Rated) and `.pot` regeneration via `npm run makepot`.

## Release note

This completes the **free v1.0 feature surface**. Tagging **v1.0.0** for WordPress.org is gated on: Plugin Check = 0 errors on this code, the editor/admin/importer verification above, and the `.pot` regenerated. Current version: **0.5.0** (pre-release).

## Next

**Phase 5 — Pro foundation:** the `ndv-reviews-pro` add-on (license gate intentionally **open** during development), unlimited criteria, video reviews, rating styles, anonymous/highlight/country, admin reply + saved replies.
