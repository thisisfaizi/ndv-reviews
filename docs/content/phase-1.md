# Phase 1 — Core Reviews {badge:wip:CODE-COMPLETE}

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

Status: **verified at runtime** (a multi-criteria review submitted, moderated, and shown on the storefront with the verified-owner badge and cached 4★ overall). Photo upload had two bugs, now fixed — see note below.

- ☑ Criteria render on the form; overall computed & cached on save (cached 4★ shown on the product).
- ☑ Free hard-caps at 3 active criteria with an upsell notice.
- ☑ AJAX submit, no full reload; client + server validation ("awaiting moderation" inline message).
- ☑ Images validated (mime/size/count) and stored via WP media + `ndvr_review_media` (after the upload fix below — pending one re-test).
- ☑ Honeypot + nonce + rate-limit enforced; reCAPTCHA optional.
- ☑ Verified flag set on submit (review showed *verified owner*).
- ☑ Pending reviews stay out of the front end; approved review renders in the WooCommerce reviews tab.

### Fix: photo upload ("Specified file failed upload test.")

Two bugs, both fixed in 0.2.1:
1. Used `media_handle_sideload()`, whose `is_uploaded_file()` check rejects genuine HTTP uploads → switched to `media_handle_upload()`.
2. `normalize_files()` ran `wp_unslash()` on `$_FILES` — WordPress never slashes `$_FILES`, and unslashing strips backslashes from **Windows** temp paths (`C:\…\php123.tmp`), breaking `is_uploaded_file()`. Now reads `$_FILES` raw and sanitizes only name/type.

### How to verify (one pass proves the set)

1. **Re-activate** the plugin (Plugins screen → deactivate/activate) so default criteria (Quality/Value/Service) seed. Confirm under **NDV Reviews → Rating Criteria**.
2. Open any product, scroll to **Reviews**, rate the criteria, write a review, attach a photo, accept consent, submit. Expect an inline "awaiting moderation" message (no reload).
3. **Comments → Pending**: the review is there, unapproved. Approve it.
4. Reload the product: the approved review shows in the reviews tab; the star aggregate updates.
5. Re-run **Plugin Check** on the freshly built zip (`bash bin/build-zip.sh`) and confirm 0 errors on the Phase-1 code.

## Notes & decisions

- The form reuses WooCommerce's review scaffolding (so it appears natively in the Reviews tab) but routes submission through our AJAX endpoint — bypassing `wp-comments-post.php` and Woo's single-rating requirement. Full per-criterion and photo display is **Phase 2**.
- Aggregate recalculation runs on creation (when auto-approved) and will also hook comment-status transitions in Phase 2's moderation work.

## Next

**Phase 2 — Moderation + display:** admin list table (filter/bulk/edit), 2 display templates, summary + criteria graphs, filtering/sorting/AJAX pagination, helpful voting, JSON-LD schema.
