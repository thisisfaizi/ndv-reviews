# CONTEXT.md — decisions, constraints, rejected approaches

Append durable decisions here (with the reason). If an approach was tried and rejected, record it so no one
re-proposes it.

## Standing decisions
- **Nothing is published yet** (not free on WP.org, not Pro). All versions are internal/pre-release —
  reserve **1.0.0** for first public launch, don't inflate. Pro was realigned 1.9.1 → 0.9.9 (2026-07) to
  pair with free; both march to a shared 1.0.0. Next feature bump: prefer `0.9.9 → 0.10.0` over `0.9.10`.
- **`ndvr_`/`ndv_reviews_`/`ndv-reviews/` prefixes are frozen.** The rebrand deliberately did not rename
  the internal prefix — options, tables, meta, nonces, hooks, CSS classes and Pro all key off it. Renaming
  = data loss + Pro breakage. Not a cleanup.
- **Reviews are `WP_Comment` rows** (type `review`/`comment`), not a CPT. Aggregates are Woo product meta
  (`_wc_average_rating`/`_wc_review_count`) synced by `RatingCache`/`AggregateStore`.
- **Free ↔ Pro:** Pro boots on `ndv-reviews/loaded`, extends via documented hooks only, never edits free
  files; removing Pro leaves free fully working. New Pro needs = new free hooks in `CONTRACTS.md`.
- **Licensing deferred by user brief.** The gate is a single choke point (`License::is_pro_active()`
  defaults `true`; `License::can()` + `FeatureFlags`). Do not wire license checks into feature code —
  flipping those two methods is the entire enforcement surface later.
- **Elementor grid/loop is folded into the free `ndvr-reviews` (Review Section) widget**, NOT a separate
  widget — per user (2026-07). Pro injects controls + filters `render_content`. The standalone `ReviewGrid`
  widget was built then **removed**; don't reintroduce it.
- **Google review TEXT cannot be fetched server-side from just a link** — the review endpoint needs a
  per-session token minted by JS (verified live: `listugcposts` returns empty; `listentitiesreviews` 404s).
  Link-only mode returns the **aggregate** (rating + count) badge only; individual review cards require the
  Places API key or a proxy (that's how "no-key" plugins do it — their server runs the session).

## Rejected approaches (don't re-try)
- Scraping Google review text via `wp_remote_get` with the feature id / `kEI` token — returns an empty
  envelope; abandoned in favour of the aggregate badge + optional API key. (Pro 1.8.x.)
- A standalone "NDV Review Grid" Elementor widget — replaced by folding into the Review Section widget.

## Known open defects (tracked in PRODUCTION-PLAN.md)
Phase 1 (C1–C5, B1–B2), Phase 3 (B3/B4/B6 free; C4/C6/C7 Pro), Phase 4 (T-A1 accessibility; T-UI1 UI
polish), and Phase 5 (T-M3b marquee data gaps — category filter, min-rating starvation, speed
normalization, double-row variant; T-UI2 remaining UI polish — CriteriaPage/RequestsPage/ToolsPage card
migration, Pro QandA Moderation hex colors, the `external_target_post` setting given a real field) are all
shipped — see TASKS.md. Remaining backlog: **T-L1** licensing system only (deferred by design — per the
original brief, do not implement until the rest of the build is complete).
