# Phase 8 — Widgets+, Social, Feeds, Reputation (Pro) {badge:wip:CODE-COMPLETE}

> **Goal (build-plan §15):** Carousel/gallery/badge/wall, share buttons, Google Shopping feed + badges, shared-variation pools, reviewer reputation.

## Widget catalog (`Widgets/Catalog.php`)

Built on the **free plugin's `ReviewQuery`**, each a shortcode with conditional asset loading:

| Shortcode | Widget |
|---|---|
| `[ndvr-carousel]` | Horizontal scroll-snap carousel of review cards |
| `[ndvr-gallery]` | Clickable **UGC photo grid** (customer photos → review) |
| `[ndvr-wall]` | **Wall of love** (masonry testimonial grid) |
| `[ndvr-badge]` | Floating site-wide **★ aggregate badge** (or per-product) |

All accept `product_id`, `limit`, `min_rating`, `with_media`, `verified`.

## Social share (`Social/ShareButtons.php`)

Share links (X / Facebook / WhatsApp) under each review, injected via the free `ndv-reviews/review_item_after` hook.

## Google Shopping feed (`Feeds/GoogleShopping.php`)

A Merchant Center **product-review XML feed** at `?ndvr_feed=google` (cached 6h) so ratings can appear in Google Shopping / PLAs — beyond on-page schema.

## Reputation (`Reputation/TopReviewer.php`)

A **"Top Reviewer"** badge for authors with ≥ N approved reviews (threshold filterable), shown via the review-item hook.

## Acceptance criteria (§7.12, §7.13, §7.14, §7.16)

Status: **code-complete, lint-clean; pending a user pass.**

- ☐ Each widget renders as a shortcode; lazy-loads media; configurable source.
- ☐ Share links prefill correctly.
- ☐ Feed validates against Google's product-review spec; paginates/caches.
- ☐ Top-reviewer badge appears for qualifying authors.

### Deferred to an 8.x follow-up
Blocks/Elementor wrappers for the new widgets (shortcodes ship now), server-generated share **image cards**, scheduled social **auto-posting** + live feed embed, **shared-variation rating pools**, and the compliant public-review CTA / location forms (§19.5).

## Next

**Phase 9 — Analytics, importers+, developer (Pro) → Pro v1.0.**
