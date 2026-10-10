# PRD review round 2 (2026-10-10): open BLOCKER/MAJOR items to fix in rev 3

PASS: RR-04, RR-15, RR-20, RR-23, RR-25. RR-09 rev 3 already absorbed batch-A items #1–#8. Shared-document items (S) are fixed by the owner in PRD-00/RR-00/RR-00b.

## Shared (fixed by the owner)
- S1 **BLOCKER, schema version rule.** Versions are numbered strictly upward **at merge**; never ship a lower number after a higher one. Remove "keeps its number" and the up-front table. `is_current( Installer::V_<FEATURE> )` uses constants defined in code.
- S2 F3 no longer mentions transparency steps.
- S3 **F6 settings registry:** every entry carries its `page` (settings | reminders | design); each handler processes only its own page's keys. Add an AC.
- S4 **F5:** add the filters `review_body_html` / `review_title_html` (RR-16) to the list of hooks overrides must keep.
- S5 **F2:** uninstall deletes order meta without WooCommerce loaded, using a prepared DELETE on `{prefix}wc_orders_meta` (if the table exists) and on postmeta.
- S6 **Old free + new Pro:** free defines `NDVR_API` (int). Pro features needing new free API register only when `defined('NDVR_API') && NDVR_API >= N`; otherwise the feature stays off and Pro shows one notice ("Update Rosette Reviews to use …"). This replaces scattered `method_exists` guards (RR-01, 02, 06P, 10, 17, 18).
- S7 **RR-00b:** move the `list_link` assignment to RR-09 (done); E12 lists `check_eligibility` and `is_current`; RR-00 text already includes list_link.
- S8 **The `has_reviewed` definition (one):** `post__in [id, pool]`, matching email OR user_id.

## RR-03
1. **The `moderation` sentence is false for native posts.** ReviewForm.php:119-121 passes rated native POSTs to core, which can auto-approve via `check_comment()`. Add a free `pre_comment_approved` callback returning 0 for front-end top-level reviews on reviewable posts (keep spam/trash), plus an AC.
2. **The `verified` sentence** needs a variant while a third-party import exists, and must quote the filtered badge text.
3. **The `access`/`requests` sentences:**
   - drop "otherwise" when reviews are closed or any `validate_review` gate is active (Pro PurchaseGating row);
   - render only on `product` posts or product_id 0;
   - use the opt-in variant only when legacy orders = skip.
4. **Fresh-install default:** default off everywhere, with the notice. Or keep it on but put the "we don't remove negative reviews" clause behind an explicit merchant checkbox. The owner's decision: default **off**, notice on our screens.

## RR-05
1. AC8 tests the removed `Engine::run_step`; use "`maybe_start()` creates no row (`ndvr_nothing_to_review`)". Move the Dispatcher half of AC7 to RR-06P. Fix the stale §5 text.

## RR-06
1. **BLOCKER:** the follow-up send listener must return early unless `origin==='free' && source==='followup'`. Add an AC that Pro steps 2–3 aren't cancelled. Follow-ups are scheduled from the `ndv-reviews/request_sent` action (RR-09 rev 3), not called by `process()`. Use instance services from the container, not static calls.

## RR-07
1. **BLOCKER: silence isn't consent.** Opt-out mode records `not_objected`, shown as "Didn't opt out at checkout on {date}" and exported as "Did not object". Opt-in honours only `yes`. AC: switching opt-out → opt-in doesn't send to `not_objected`.
2. **BLOCKER: block checkout persists the field to the customer** (WC 11.2 Routes/V1/Checkout.php:398-409, :473-482; CheckoutTrait.php:306-349; CheckoutSchema.php:282, :319-335), so returning customers see it pre-ticked. Delete the customer-side copy in the record listener and before the response is built (spike 8.9–10.x). AC: a second `GET /wc/store/v1/checkout` returns false.
3. Move the AC11 Dispatcher half to the Pro task.
4. Record the label registered on `woocommerce_init`; don't re-translate it in the REST request.

## RR-08
1. `WP_Locale_Switcher` caches `get_available_languages()` in its constructor, so create and remove the `.mo` fixtures in a **separate** `wp eval-file` run before each scenario.

## RR-11
1. AC10: either the questions with the same slugs must exist first, or the importer creates inactive text questions for unknown `q_<slug>` columns. Choose the second.

## RR-12
1. The counter has no live region. Announce once, in a separate polite region, when the minimum is reached or on blur, debounced.
2. The source list includes `list_link`.

## RR-13
1. `TestimonialForm::render()` enqueues the provider handle; collect.js renders explicitly on load; drop the dynamic injection (collect.js:20-33).

## RR-14
1. Per-instance ids `ndvr-report-{instance}-{id}` with `for` attributes.
2. The transparency `reports` sentence is canonical in RR-14 (signed-in reporters count; shown only when threshold > 0). RR-03 references it.

## RR-16
1. Pool-aware: `search_ids()` uses `Pool::resolve_id()`, and so does the review-count check (AggregateStore). Add an AC.
2. The no-JS GET on plain permalinks needs hidden inputs for the permalink's query arguments. Add an AC.
3. AC13 cites the §10 budget of 50 ms.

## RR-21
1. Score after `validate_review` (ReviewRepository.php:139-142), then set `$approved = 0` before `$commentdata` (:144).

## RR-22
1. **LEGAL:** decouple the recovery case and the invite from the rating. Any review the merchant opens a case on (Mark resolved / Invite) can use them; the threshold drives only the internal alert and the after-submit message. Keep the legal release gate (invites forced off until sign-off recorded).
2. **The edit window rule lives in `update()`** (shared with RR-15), with an explicit invite exemption stated in both PRDs and in the `edits` sentence. Prefill from the pending revision (RR-15 rule).
3. `update()` gains a validated 1–5 `rating` key for reviews without score rows (RR-15 too).

## RR-24
1. Bound on the indexed `wc_order_product_lookup.date_created` (local time, so widen by one day) and join `wc_order_stats` only for status.
2. Debounced Action Scheduler recompute (at most hourly), serving stale data meanwhile. The all-time trend comes from product `_wc_average_rating × _wc_review_count`. The fallback job uses `'return'=>'ids'` and the lookup/order-items tables, not full orders.
3. Benchmarks are recorded in LOG, not gated.

## Pro RR-01
1. Use S6 instead of direct calls to `Sources::*` on an old free.
2. **The disclosure must render everywhere:** free renders the pill from a core listener on `review_author_badges` (overrides keep that action). Pro hooks it itself when free lacks E9 (S6). If neither works, CouponReward stops issuing coupons.
3. **LEGAL:** reward eligible interactive submissions after a rating-independent check: issue the coupon when the review is not spam or trashed 7 days after submission, or on approval if that comes first. A stamped review can be rejected only with a non-rating reason from a whitelist (shared with RR-15).
4. E9 values are `offered` and `received`, each with a free string.

## Pro RR-02
1. **LEGAL:** don't use the verified slot for imported verification. Print a separate pill via `review_author_badges` ("Marked verified on {app}"), gated by S6.
2. **Decompression bombs:** cap at 40 MP from `getimagesize()` before sideloading; write an in-flight marker that's counted as failed on the next run.
3. S6 gates the CSV helpers.
4. Fixtures: try a real Judge.me export header during the build spike; if unavailable, use synthetic fixtures (documented).

## Pro RR-17
1. S6 gating for every free symbol. AC with RR-00 absent.
2. Apply RR-22's trusted-email rule to reply notices (no notice to typed, unverified guest emails). For guest Q&A answer emails, don't include the question text unless the email is confirmed, or don't send to guest emails at all. The owner's decision: guest answer emails carry no question or answer text, only a link.

## Pro RR-18
1. **Guest video uploads** are off by default (logged-in users and landing tokens only), with a filterable site-wide hourly byte cap and retention cleanup for videos on reviews pending more than 30 days.

## Pro RR-19 (complex): the owner's decision is to build it last
1. A `wp_insert_comment` listener plus an hourly sweeper move reviews created on members outside `create()` (WC REST and similar).
2. Join and restore move descendants (replies) with their parent.
3. Legacy groups: keep Pooling's group branch until ProductGroups registers, keep undefined map entries, and adopt by `_ndvr_order_id`/token when unambiguous.
4. Licence lapse or deactivation recounts the members; the Tools card warns; at boot Pro re-joins members that hold their own reviews; the move job re-checks membership before each batch.
5. Label every review whose origin differs from the viewed product.
6. Validate that members share `product_cat` (RR-20) and use the pool in RR-24's stale detection.
