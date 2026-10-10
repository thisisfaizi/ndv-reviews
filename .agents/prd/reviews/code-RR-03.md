# Code review: RR-03 "How reviews work" transparency notice

Reviewer: independent (read-only) · Date: 2026-10-10 · Spec: `RR-03-transparency-notice.md` rev 4 + `PRD-00-conventions.md`
Method:
- Read `Display/Transparency.php`, `templates/transparency.php` and `.agents/qa/rr-03.php` in full.
- Read the RR-03 hunks of `git diff HEAD` for the changed files listed in the brief.
- Ran `php -l` on all 12 changed PHP files (clean, PHP 8.3 CLI).
- Grepped free and Pro for every path that creates or approves a review: `should_approve`, `wp_insert_comment`, `wp_new_comment`, `wp_set_comment_status`, `comment_approved => 1`.
- Read the core and WooCommerce paths behind the native hold (WP 7.1.3 and WC 11.2.0 in `D:/.devcache/qa-site`): `wp_allow_comment()` (comment.php:1365-1407), `wp_new_comment()` (:2440), the WP REST comments controller (:749-795), the WC REST v1 and v3 review controllers, `WC_Comments`, WC `Internal/OrderReviews/SubmissionHandler`, and Akismet.

**Verdict: CHANGES REQUESTED.** No blockers: the notice is off by default and nothing changes on the storefront until the merchant switches it on.

Five MAJOR findings. Each one makes a sentence false in a reachable configuration, and none of them shows in the settings preview (simple product, front-end assumptions). So "the merchant read it" does not catch them:
- Three are in free: the `is_admin()` hole in `hold_native_review` (which also covers WooCommerce 11's own order-review flow), rated replies, and the `access` sentence on non-product posts.
- One is the missing pooling guard on `average`.
- One belongs to Pro, but has to ship before the notice is used together with Pro AI auto-publish.

The sentence machinery itself is sound: keyed filter, ordering, escaping, the older-Pro guards, settings flow, dismissal, uninstall and surfaces.

---

## Confirmed clean (checked explicitly)

- **Older-Pro guards hold on today's Pro.**
  - `Automation\Engine::suppress_free_reminder` (Engine.php:59-73) returns false for automation or any ESP, so the `requests` sentence disappears.
  - `Moderation\Plus` registers `should_approve` (Plus.php:39), so `moderation` drops "We check… before they appear".
  - `Plus::validate` and `PurchaseGating::validate` register `validate_review`, so the open `access` variant disappears.
  - All Pro features sit behind one licence gate (`FeatureFlags::all()`).
- **Interactive free paths never auto-approve.** Landing.php:343, ReviewForm.php:563 and TestimonialForm.php:290 all pass `approved => 0`. The only free approval is the admin moderation screen (Moderation/Page.php:306).
- **Akismet / spam ordering.** Akismet hooks `pre_comment_approved` at 10 (class.akismet.php:102), and the hold runs at 99, keeping `spam`, `trash` and `WP_Error`. The disallowed list sets trash or spam before the filter.
- **WP REST `/wp/v2/comments`** goes through `wp_allow_comment()` (controller :752), so top-level comments on reviewable posts are held. `is_admin()` is false there.
- **`verified` sentence matches every writer of `_ndvr_verified`:**
  - Landing forces it only for `order`/`customer` tokens (Landing.php:356-362).
  - `list_link` goes through `create()` → `VerifiedBuyer` with an email match, which is the "email address has a matching order" clause.
  - Onsite and form reviews use the account only (VerifiedBuyer.php:48-56).
  - WooNative uses the email or the account (WooNative.php:80).
  - The CSV `verified` column is covered by the import variant.
  - Pro ManualReviews matches the email against orders. Pro External writes 0.
  - The card reads `_ndvr_verified` (ReviewQuery.php:272) and quotes the same filtered badge text (review-item.php:52).
- **`third_party_import` / transient:**
  - Csv fires `third_party_import_done('csv')` at the end of a run.
  - The listener deletes the transient whatever the slug.
  - `type__in` stops WooCommerce's `comments_clauses_without_product_reviews` from hiding product reviews (ReviewsUtil.php:63).
  - The transient is in `Uninstall::registry()['transients']` and the user meta in `user_meta`.
- **Escaping:**
  - Template: every sentence `esc_html`, extra text through `wp_kses` again at output, `data-surface` through `esc_attr`.
  - Settings preview: `esc_html` per sentence, `wp_kses` on the extra text, `esc_textarea` in the field.
  - `wp_kses` keeps `href` but strips `javascript:` (allowed protocols).
  - `sanitize_extra` uses `wp_kses` + `mb_substr` (WP ships an `mb_substr` polyfill).
- **Settings flow:**
  - `render_settings` prints the `transparency_extra` marker itself, because the field has no render callback. `render_card_fields` prints the `transparency_enabled` marker.
  - `sanitize_page` only touches listed keys. The save handler checks the nonce, then the capability, then merges.
- **Dismissal:** `check_admin_referer`, then `Caps::manage()`, then `update_user_meta`, then `wp_safe_redirect`. Same pattern as HealthCheck. The notice is limited to the screen rule and `Caps::manage()`.
- **Surfaces:**
  - Tab: summary, then the notice, then `after_summary` (Renderer.php:248-266). `after_summary` keeps one argument at the same place, so Pro Ai.php:57 and PublicCta are untouched.
  - `Widgets::summary/criteria_graph/reviews(show_summary)`, the block, the Elementor SummaryWidget, the Pro LoopModule summary and the classic widget (`'widget'`, off by default) all fire `summary_footer`. `Widgets::summary()` never fires `after_summary`.
- **Template:** native `<details>`/`<summary>` (keyboard and screen-reader support built in, closed by default), `:focus-visible` outline, token colours only.
- **PHP 7.4 / WP 6.0:** no 8.x syntax (no `match`, no nullsafe, no union types, no named args). `wc_get_order_status_name` exists in WC 8.0.
- **Performance:** settings reads are cached, and `wc_get_product()` is already loaded on product pages. `wc_get_products()` runs only in `render_settings` (admin). No JS. (See MINOR 6 for the transient.)
- **Changelog:** readme.txt says both behaviour changes (native posts always held, testimonial form follows "verified owners only").

---

## MAJOR

### M1. `hold_native_review` skips every admin-ajax submission, including WooCommerce 11's own order-review flow
`includes/Forms/ReviewForm.php:115`: `|| is_admin()`. `is_admin()` is true for every request to `admin-ajax.php`, logged-out ones included.

**Failure scenario (first-party):**
1. A store on WC 11.2 turns on the WooCommerce feature `customer_review_request` (non-experimental, togglable in Features).
2. Customers submit reviews through `wp_ajax_nopriv_woocommerce_submit_order_reviews` (SubmissionHandler.php:58-59). That path calls `wp_new_comment()` with `comment_previously_approved` forced to 0 (:488-501).
3. With "Comment must be manually approved" off, core approves the review (`check_comment()` passes), and the hold returns that approval unchanged because `is_admin()`.
4. Reviews "left on this store" are published unchecked while the notice says "We check reviews left on this store before they appear."

The same applies to any AJAX comment plugin or theme (wpDiscuz-style `wp_ajax_nopriv_*` handlers that call `wp_new_comment`).

This is a spec defect too: §6 and §5 wrote `is_admin()` and meant "the dashboard Reply box".

**Fix:** keep core's result only for real admin screens and for AJAX by moderators.
```php
if ( is_wp_error( $approved ) || in_array( $approved, array( 'spam', 'trash' ), true ) ) {
	return $approved;
}
// Dashboard screens, and the dashboard/editor "Reply"/"Add comment" boxes (AJAX, moderators only).
if ( ( is_admin() && ! wp_doing_ajax() ) || ( wp_doing_ajax() && current_user_can( 'moderate_comments' ) ) ) {
	return $approved;
}
```
Also handle WooCommerce's edit path. `moderate_edited_review()` (SubmissionHandler.php:446-469) sends `comment_ID` and then calls `wp_set_comment_status()`. Today that can publish a pending review the customer edits (core says 1). Holding blindly would instead unpublish an approved one, which breaks RR-00 F8.1 ("edits never unpublish").

When `! empty( $commentdata['comment_ID'] )`, return `'1' === (string) get_comment( $commentdata['comment_ID'] )->comment_approved ? $approved : 0`. That only ever lowers the status.

**Spec and tests:**
- Update RR-03 §5/§6 and CONTRACTS.md:77.
- Add AC19 cases:
  - a guest `wp_new_comment()` with `DOING_AJAX` (or the `wp_doing_ajax` filter returning true) stores 0;
  - a moderator under AJAX keeps core's 1;
  - the `set_current_screen( 'edit-comments' )` case that spec AC19 already asks for, which is not in the harness (rr-03.php:514-560 has guest, spam and reply only).

### M2. Rated replies bypass the hold and count toward the average
`ReviewForm.php:118-120` exempts any `comment_parent` > 0. WooCommerce does not look at the parent:
- `update_comment_type` makes a product reply a `review`;
- `add_comment_rating` adds the `rating` meta from `$_POST['rating']`.

Free's `RatingCache::recalc_product()` (RatingCache.php:76-86) counts every approved `review`/`comment` with rating meta, and has no `comment_parent = 0` condition. WooCommerce's own average (class-wc-comments.php:538-547) has none either, so the hold is the only gate.

**Failure scenario:**
1. Moderation is off, so `comment_previously_approved` alone holds first-timers.
2. Anyone POSTs to `wp-comments-post.php` with `comment_parent` = any approved review and `rating=1` (or 5).
3. Core approves it for a returning author (or always when both discussion options are off), and the hold keeps that.
4. A star rating that was never checked appears in the average. "We check reviews… before they appear" and "average of all published reviews" are both wrong about what is counted.

**Fix (both):**
- In `hold_native_review`, exempt a reply only when it carries no rating or the author can moderate: `if ( ! empty( $commentdata['comment_parent'] ) && ( empty( $_POST['rating'] ) || user_can( (int) ( $commentdata['user_id'] ?? 0 ), 'moderate_comments' ) ) ) return $approved;` (phpcs: read-only, no nonce on core's form).
- Optionally, as defence in depth, add `AND c.comment_parent = 0` to `RatingCache::recalc_product()`. A store reply should never carry a star rating, but this changes `_wc_average_rating`, so it needs its own changelog line.
- Add an AC: with moderation off, a rated guest reply stores 0, and an unrated moderator reply keeps 1.

### M3. `access` "Only customers who bought the product can leave a review." is false on non-product reviewable posts and store-wide
`Transparency.php:117` and `:181-182` use `verification_required` whatever the post type. The WooCommerce rule is enforced for products only:
- TestimonialForm.php:257 checks `'product' === get_post_type()`;
- WooCommerce `validate_product_review_verified_owners` returns early for non-products (class-wc-comments.php:676);
- the native comment form on a page or CPT is open.

**Failure scenario:**
1. `reviewable_post_types` includes `page` (or a CPT), and WooCommerce "verified owners only" is on.
2. `[ndvr-summary]` or the Elementor summary widget runs on that page. `resolve_id()` falls back to `get_the_ID()` (Widgets.php:75), so the notice gets the page id.
3. The notice says only buyers can review, while anyone can review that page through `[ndvr-testimonial]` or the comment form.
4. The store-wide variant (`product_id` 0, `[ndvr-transparency]`) is false the same way whenever extra reviewable types exist.

The non-product notice also says "this product" in `access` and `average`. And a non-reviewable page with comments open gets the open variant ("can also leave a review") for something that isn't reviewed at all.

**Fix:**
- In `facts()`:
  - `verification_required` = option `yes` **and** (`'product' === $type`, or `0 === $product_id` with `PostTypes::all() === array( 'product' )`).
  - `reviews_open` = false when `$product_id` is set and `! PostTypes::is_reviewable( $product_id )`.
- For a non-product post, either use item-neutral variants ("…of this item") or leave out `access`.
- In `on_summary_footer`, render nothing when `$product_id` is neither 0 nor reviewable.
- Add an AC with a reviewable page and verification on: no "Only customers" sentence.

### M4. `average` has no free-side guard for pooled products (Pro Pooling groups)
`Transparency.php:197-201`. The summary shows the pool's aggregate (`Display/Summary.php:27` → `Pool::resolve_id`). Pro Pooling maps arbitrary products onto a master (`Pooling::resolve`, Pooling.php:50-55, `GROUP_OPTION`).

**Failure scenario:** Pro with a group A+B, an older Pro without a transparency row (Pro has no `transparency` code at all today). The notice on A says "The star rating is the average of all published reviews of this product", but the number includes B's reviews.

The spec gives the other three sentences an older-Pro guard (§11) but not this one, and the settings preview (a simple, unpooled product) can't show it.

**Fix:**
- Add fact `pooled` = `false !== has_filter( 'ndv-reviews/review_pool_id' )`, the same `has_filter` pattern as the other guards.
- While it's true, use a sentence that holds either way, for example "The star rating is the average of the published reviews it is based on, shown below." (owner to word it). Pro replaces it with its group wording.
- Add AC16 case: a stub `review_pool_id` listener switches `average`.

### M5 (Pro, must ship before the notice is used with Pro AI auto-publish). Sentiment auto-publish outside `should_approve`
`rosette-reviews-pro/includes/AI/Ai.php:137-149` calls `wp_set_comment_status( $comment_id, 'approve' )` for pending reviews with positive sentiment. It doesn't go through `should_approve`.

Free's short `moderation` sentence works today only because `Moderation\Plus` happens to register `should_approve` under the same licence. If `ndv-reviews-pro/can` turns off `moderation_plus` while `ai_auto_publish` stays on, "We check reviews left on this store before they appear" is false.

Separately, publishing positive reviews at once while negative ones wait is the rating-asymmetry problem that Plus's own docblock (Plus.php:44-49) cites against the star floor:
- FTC 16 CFR 465.7 (suppression based on negative sentiment);
- the CMA guidance under DMCC on treating positive and negative reviews differently.

The free sentence "We don't remove reviews because they are negative" stays literally true, but a shopper is misled about which reviews appear first.

**Fix (Pro TASKS):** either drop the sentiment condition (publish by spam score only), or ship the `moderation` row that says some reviews are published automatically. Have Ai.php add its own `transparency_facts` `auto_approve => true` when `ai_auto_publish` is on, so the free guard doesn't depend on Plus.

---

## MINOR

1. **Reviews published before this version.** With WordPress defaults (moderation off, "previously approved" on), repeat reviewers, staff and post authors were auto-approved. On stores with both options off, everyone was. "We check reviews… before they appear" is present tense over a list that may include those reviews. Spec §7 accepts it, and the exposure is limited.
   - Fix: in `render_settings`, when `get_option( 'comment_moderation' )` is `'0'`, add a help line under the checkbox: "Reviews published before Rosette Reviews 1.x through the WordPress comment form may not have been checked: review them before switching this on."
2. **Latent: list links ignore "verified owners only".** Landing.php:304-326: a `list` token (RR-09 `queue_for_email`, public since NDVR_API 3) lets the list address review any listed product. With verification required, `access` "Only customers who bought…" is false as soon as any caller (Pro BulkCampaign, planned) uses it.
   - Fix: in `handle_submit`, for `$is_list` with `woocommerce_review_rating_verification_required = yes` and a product, refuse unless `wc_customer_bought_product( $email, 0, $product_id )`. Or drop such products in `create_list_token()`.
3. **WP REST bypasses "verified owners only".** WP REST `create_item` doesn't fire `pre_comment_on_post`, so a logged-in non-buyer can create a top-level comment on a product via `/wp/v2/comments`. It is held, so not published unchecked, but it's still a submission the `access` sentence says can't happen.
   - Fix: a `rest_pre_insert_comment` callback that applies the same rule for products and returns `WP_Error` (403).
   - Related note: WC REST v1 inserts approved reviews directly (v1 controller :543) and v3's `status` param can approve them. Both need API keys with edit rights (staff), so they're acceptable as "checked", but CONTRACTS should say so.
4. **CONTRACTS.md misses the E9 empty-review contract.** Spec §3 (verified row) requires CONTRACTS.md to state that `verified_badge_text` callbacks receive an empty `$review` from Transparency (Transparency.php:121). CONTRACTS.md:87 doesn't. Add it.
   - Related: `show_verified_badge` returning false hides every label, but the sentence still describes one. Consider `apply_filters( 'ndv-reviews/show_verified_badge', true, array() )` as part of the `verified` fact.
   - Related: Pro Elementor `VerifiedPart` has its own label control (VerifiedPart.php:56) that can differ from the quoted text. That's a Pro task.
5. **Block label is wrong.** `assets/js/blocks.js:72` reuses `productControl` ("Product ID (0 = current product)"), but the transparency block and shortcode treat 0 as store-wide (`Transparency::shortcode`, :298).
   - Fix: give the block its own control label, "Product ID (0 = whole store)".
6. **Transient read is a query per product page.** A transient with an expiry isn't autoloaded, so without a persistent object cache `get_transient( 'ndvr_tp_import' )` costs 2 option queries (timeout + value) on every page with a summary. Spec §10 says "no query on a warm cache".
   - Fix: store it as an autoloaded option (`update_option( …, '0'|'1', true )`) cleared by the same hooks, or a no-expiry transient (autoloaded) since invalidation is explicit. Add a per-request static memo for pages with several surfaces.
   - Also listen on `added_comment_meta` for `_ndvr_import_hash`. Pro ProImporter writes the hash after `create()` (ProImporter.php:443) with no status transition, so the `imported` sentence waits up to 12 h until Pro fires the action.
7. **Contract drift: dismissal GET argument.** Spec §6 names it `ndvr_transparency_dismiss`; the code uses `ndvr_transparency_notice`, the nonce action (Transparency.php:468, :486).
   - Fix: rename the argument, or amend the spec and CONTRACTS.md:75.
8. **Import wording.** Re-importing the store's own CSV export (`Exporter` → `Csv`) writes `_ndvr_import_hash`. The notice then says "imported from another review service" and "the label comes from the service they were imported from" (Transparency.php:176, :192).
   - Fix: "imported from a file or another review service", or skip the hash for rows whose `_ndvr_source` already marks them as this store's.
9. **External reviews (Pro) aren't detected by default.** Pro External stores approved, rated reviews on products (ExternalReviews.php:858-877) with no `_ndvr_import_hash`. Until Pro sets `external`, `source` says only "Reviews come from customers." No sentence is literally false, so this is MINOR.
   - Fix: default fact `external` from a cached count of approved `_ndvr_external_id` comments, using the same query shape as the import check.
10. **QA harness hygiene.**
    - `ac19_native_hold` sets `comment_moderation` and `comment_previously_approved` to `'0'` and never restores them (rr-03.php:515-516).
    - AC7 can print `SKIP` on a site with earlier CSV imports (rr-03.php:277). LOG.md says "44/44 (ACs 1–19)" without saying whether AC7 ran; record that.
11. **Stale comment.** TestimonialForm.php:249 says "No purchase requirement (see class docblock)", which RR-03 now contradicts for products. Update the comment and the class docblock.
12. **WooCommerce's own review-request emails.** With `customer_review_request` on, WooCommerce emails customers itself, but the `requests` sentence knows only free's reminders. That's an omission, not false. Optionally add fact `wc_requests` = `FeaturesUtil::feature_is_enabled( 'customer_review_request' )` for a later sentence.

---

## Acceptance criteria

| AC | Status |
|---|---|
| 1, 3 off by default, notice on our screens only, one `<details>` | Met (rr-03.php:128-137) |
| 2 tab order | Met |
| 4 dismissal nonce | Met (GET arg name differs, MINOR 7) |
| 5, 6 requests variants | Met |
| 7, 7b imports | Met in the code. The harness can SKIP AC7 (MINOR 10) |
| 8 average variants | Met (pooling gap M4) |
| 9, 9b, 10 filters | Met |
| 11, 12, 13 surfaces | Met (block label MINOR 5) |
| 14 TestimonialForm verified owners | Met for products. Non-product gap M3 |
| 15 kses | Met |
| 16 older-Pro guards | Met as specified. Pooling guard missing (M4). AI auto-publish not covered (M5) |
| 17 closed reviews | Met |
| 18 badge text | Met |
| 19 native hold | Partially met: the `is_admin()` clause is not tested, and the clause as specified is the M1 hole. Rated-reply hole M2 |
| 20 core flows | Reported 49 pass |

## Required before approval
M1, M2, M3 and M4 in free, with their ACs. M5 recorded as a Pro task that must ship before Pro AI auto-publish is used with the notice on (or a free fact guard that sets `auto_approve` when Pro's `ai_auto_publish` is on).
