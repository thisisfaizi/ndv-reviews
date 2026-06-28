# Phase 9 — Analytics, Developer, Importers+ (Pro) → Pro v1.0 {badge:wip:CODE-COMPLETE}

> **Goal (build-plan §15):** Analytics dashboard + export; importers (Judge.me/Loox/Yotpo/Stamped); multilingual; REST v2 + webhooks + CLI → **Pro v1.0**.

## Analytics (`Analytics/Dashboard.php`)

Admin dashboard: **reviews per month** (last 12, with bars), **average-rating trend**, and **top keywords** (stop-word-filtered frequency), with **CSV export**. Queries are indexed and bounded.

## Developer platform (`Developer/`)

- **REST API v2** — `ndv-reviews/v2`: `GET /reviews` (filter/sort/paginate, `X-WP-Total` headers) and `GET /products/{id}/summary`. Read endpoints are public (reviews are public).
- **Webhooks** — signed (HMAC `X-NDVR-Signature`) `review.created` / `review.approved` events to a configured URL, non-blocking.
- **WP-CLI** — `wp ndv-reviews recalc` (rebuild aggregates) and `wp ndv-reviews ai_backfill --limit=N` (enrich sentiment/spam).

## Importers+ (`Importers/ProImporter.php`)

Imports **Judge.me / Loox / Yotpo / Stamped** CSV exports via **fuzzy column auto-detection** (rating, reviewer name/email, title, body, date, and product id/SKU/handle resolution), using the free `ReviewRepository`. Idempotent.

## Multilingual (`Multilingual/Wpml.php`)

Translates criteria names through **WPML / Polylang** via the free `ndv-reviews/criteria_name` filter — labels follow the active language.

## Acceptance criteria (§7.17, §7.18, §10)

Status: **code-complete, lint-clean; pending a user pass.**

- ☐ Analytics queries performant; date range; CSV matches on-screen.
- ☐ Importers map fields and are idempotent.
- ☐ REST endpoints capability-aware and documented; webhooks signed (HMAC).
- ☐ CLI commands run.

### Deferred to a 9.x follow-up
Request→review conversion funnel + sentiment-trend charts, criteria heatmap, the remaining importers (Site Reviews / Amazon / AliExpress), and deeper WPML/Polylang/TranslatePress content translation.

## Status — Pro v1.0

This completes the build-plan's phase sequence (0–9). **Free** `ndv-reviews` carries the full v1.0 feature surface; **Pro** `ndv-reviews-pro` reaches **v1.0** with foundation, automation, incentives, Q&A, AI, the widget catalog, social, feeds, reputation, analytics, importers+, multilingual, and the developer platform — all through the free plugin's documented hook API, licensing intentionally open for testing.

**Before public release:** run Plugin Check on the free build, the runtime verification passes noted in each phase doc, regenerate the `.pot`, and wire Pro licensing (the `License` interface is ready).
