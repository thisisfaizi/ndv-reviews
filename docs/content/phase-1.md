# Phase 1 — Core Reviews {badge:done:COMPLETE}

> **Goal (build-plan §15):** Review form (photo, consent, anti-spam), multi-criteria (≤3), verified badge, store as comment + custom tables, overall rating cache.
> **Done when:** a guest/customer can submit a multi-criteria photo review; it appears after moderation.

## What was built

### Reviews domain (`includes/Reviews/`)
- **Criteria.php** — immutable criterion value object.
- **CriteriaRepository.php** — CRUD for `ndvr_criteria`, default seeding (Quality / Value / Service), and the **free cap of 3 active criteria** enforced on insert/activate. The cap is filterable via `ndv-reviews/max_criteria` so Pro raises it without touching free code.
- **RatingCache.php** — computes a review's overall rating as the **mean of its criteria**, caches it in `_ndvr_overall_rating` (decimal) and the WooCommerce-native `rating` meta (integer 1–5). Recomputes a product's aggregate (`_wc_average_rating`, `_wc_rating_count`, `_wc_review_count`) and clears Woo transients — so native UI and schema stay correct.
- **VerifiedBuyer.php** — verified-buyer detection via WooCommerce's own `wc_customer_bought_product()` (HPOS-safe), filterable through `ndv-reviews/is_verified_buyer`.
- **ReviewRepository.php** — creates a review as a comment (`comment_type = 'review'`, pending by default) plus criteria scores, media rows, and meta (`_ndvr_recommend`, `_ndvr_title`, `_ndvr_source`, `_ndvr_verified`, `_ndvr_order_id`). Fires `ndv-reviews/review_created`.

### Forms (`includes/Forms/`)
- **AntiSpam.php** — honeypot, per-IP (hashed) rate limit, and optional reCAPTCHA v3 (site owner's keys). IP is never stored raw. Thresholds filterable.
- **Upload.php** — validates and imports review photos through the WordPress media API (mime/size/count checks; respects `photo_uploads` + `max_photos`).
- **ReviewForm.php** — injects the multi-criteria field set into WooCommerce's native reviews area via `woocommerce_product_review_comment_form_args` (replacing Woo's single rating select), enqueues assets **only on product pages**, and processes submissions over **AJAX** (nonce + anti-spam + capability-free public endpoint with full validation). No page reload.

### Admin (`includes/Admin/`)
- **CriteriaPage.php** — top-level "NDV Reviews" menu + **Rating Criteria** screen: add / activate / deactivate / delete criteria, all nonce- and capability-guarded (`manage_woocommerce`), with the free-cap upsell notice at 3 active.

### Front-end assets (`assets/build/`)
- **reviews.css** — accessible star-rating control (radio-based, keyboard-operable, `prefers-reduced-motion` aware), recommend + consent fields, success/error messaging.
- **reviews.js** — vanilla (no jQuery): intercepts the form, optionally fetches a reCAPTCHA token, submits via `fetch` + `FormData` (so photos upload), and renders the result inline.

## Acceptance criteria (§7.1, §7.2, §7.3)

- ☑ Criteria render on the form; overall computed & cached on save.
- ☑ Free hard-caps at 3 active criteria with an upsell notice.
- ☑ AJAX submit, no full reload; client + server validation.
- ☑ Images validated (mime/size/count) and stored via WP media + `ndvr_review_media`.
- ☑ Honeypot + nonce + rate-limit enforced; reCAPTCHA optional.
- ☑ Verified flag set on submit (via `wc_customer_bought_product`).
- ☑ Pending reviews stay out of the front end until approved (native moderation).
- ☐ End-to-end submit verified on a live product page — *manual test once the storefront is reachable.*

## Notes & decisions

- The form reuses WooCommerce's review scaffolding (so it appears natively in the Reviews tab) but routes submission through our AJAX endpoint — bypassing `wp-comments-post.php` and Woo's single-rating requirement. Full per-criterion and photo display is **Phase 2**.
- Aggregate recalculation runs on creation (when auto-approved) and will also hook comment-status transitions in Phase 2's moderation work.

## Next

**Phase 2 — Moderation + display:** admin list table (filter/bulk/edit), 2 display templates, summary + criteria graphs, filtering/sorting/AJAX pagination, helpful voting, JSON-LD schema.
