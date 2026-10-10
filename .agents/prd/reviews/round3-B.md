# PRD review round 3, batch B (2026-10-10): RR-11 to RR-16, RR-20 to RR-25

Reviewer: adversarial final pass, read-only (files and grep only). Only BLOCKER and MAJOR items are listed. Minor notes are at the end and are non-blocking.

## PASS (prd-ok)
RR-11, RR-12, RR-13, RR-16, RR-20, RR-23, RR-24, RR-25.

Not yet: RR-14 (1 MAJOR, small), RR-15 (3 MAJOR), RR-21 (1 MAJOR), RR-22 (4 MAJOR). There are also cross-document fixes in Pro RR-01 and Pro RR-17 (findings 1 and 2).

## Round 2 items: all resolved
- **RR-11 #1:** an unknown `q_<slug>` creates an inactive Short text question (§6, AC10).
- **RR-12 #1, #2:** a separate debounced polite region (§3, AC7); `list_link` is in the source list (§2, AC8).
- **RR-13 #1:** the provider handle is enqueued in the render paths and is a dependency of `ndvr-collect`; explicit render; the injection is removed (§3, §5, AC2).
- **RR-14 #1, #2:** per-instance ids with `for` (§3, AC15–16); the canonical `reports` sentence (§8), which RR-03 now only references (RR-03 table row RR-14).
- **RR-16 #1, #2, #3:** the pool-aware lookup and count (§6, §8.4, AC14); hidden inputs for plain permalinks (§3, AC15); AC13 cites the 50 ms budget.
- **RR-21 #1:** score and hold after `validate_review` and before `$commentdata` (§4, §5, AC10–11).
- **RR-22 #1, #2, #3:**
  - case and invite are decoupled from the rating (§1, §3, AC17);
  - the window is enforced in `update()`, with the `invite` exemption stated in both PRDs and in the `edits` sentence (RR-15 §6 check 5, §8; RR-22 §8);
  - the prefill rule (RR-15 §3);
  - the `rating` key (RR-15 §6, AC19; RR-22 AC19).
- **RR-24 #1, #2, #3:** indexed `l.date_created` bound (§5, AC10); debounced AS recompute, aggregate-based trend, `'return'=>'ids'` fallback (§5, §10, AC7, AC11, AC12); benchmark logged, not gated (AC8).

## Cross-document checks that hold
- **`Installer::V_*` constants:** `V_REPORTS` (RR-14), `V_FIELDS` (RR-11) and `V_CRITERIA_SCOPE` (RR-20) match the PRD-00 §4 ledger and the RR-00 F3 constant list. ACs use `V_* - 1`, never bare numbers.
- **`NDVR_API` planned levels:** 2 RR-00, 3 RR-09, 4 RR-00b, 5 RR-03, 6 RR-06, 7 RR-11, 8 RR-15, 9 RR-16, 10 RR-20, 11 RR-22, 12 RR-23, 13 RR-24, 14 RR-25.
  - No duplicates or conflicts.
  - RR-12, 13, 14 and 21 correctly claim none.
  - Each dependency comes before its dependant (RR-22 at 11 relies on RR-15 at 8).
- **`has_reviewed()`:** RR-15 §6 matches PRD-00 §6 and RR-00b §6.4. That covers `post__in [id, pool]`, email OR user_id, the second query only when the first found nothing, and the existing args.
- **F8.1:** RR-15 and RR-22 both keep the live review and write `_ndvr_pending_edit`. Unpublished reviews are updated in place and stay pending.
- **F5 hooks:**
  - RR-11 uses `review_body_after`;
  - RR-14 uses `review_foot_end`;
  - RR-15 and RR-23 use `review_meta_after`;
  - RR-16 uses `review_body_html`/`review_title_html` and `filter_bar_end( $product_id )`.
- **F7 buckets:** `report` (RR-14), `edit_u{id}` (RR-15), `list` (RR-16, only when `q` is set). None touches `submit`.
- **F6:** every new key is on an F6 card belonging to the Settings page. No key goes on Design/Reminders. RR-23 explicitly avoids DesignPage.
- **Legal:**
  - RR-14's hold reads no rating, defaults to 0 and counts only signed-in reporters;
  - RR-21's scorer input has its rating keys stripped, and the hold is inline;
  - RR-22's cases and invites read no rating, behind a legal release gate.
- **Template:** 14 sections in all 12 PRDs. Open questions are "None." in all 12.

## Code citations spot-checked (all correct unless noted)
**Free plugin:**
- **ReviewRepository.php:** 89, 91-94, 116-123, 121, 131, 139-142, 144, 150, 156, 160, 197-199, 201-205, 211, 226, 240.
- **Reviewable.php:** 24-43, 37, 59, 81-98, 86-95.
- **Moderation/Page.php:** 116-123, 122, 241, 274, 309, 348, 374, 462, 485 (`<form>`), ~535 (Photos row), 554 (`</form>`).
- **Landing.php:** 124, 132, 134, 154, 174, 193, 212, 260, 295, 298, 311-313, 317, 379-395.
- **TokenRepository.php:** 62, 92, 96-97, 123, 145, 168, 190, 215, 270, 289.
- **AntiSpam.php:** 54, 71, 138, 153, 165, 176.
- **ReviewForm.php:** 245, 351, 367, 383-389, 399, 404-412, 427, 433-440, 444-448.
- **TestimonialForm.php:** 133-135, 161, 162, 168, 257.
- **Renderer.php:** 224, 260, 314, 397, 398, 403.
- **Templates and other files:**
  - review-item.php 77 and 80;
  - CriteriaRepository.php 45, 265, 274-279;
  - Csv.php 96;
  - DashboardPage.php 368, 496;
  - JsonLd.php 73, 76, 96, 109, 199, 214;
  - SettingsPage.php 108, 207-230;
  - AggregateStore.php 26-33;
  - RatingCache.php 29-57 and 66-120;
  - Privacy.php 275-319;
  - ToolsPage.php 118-119;
  - VerifiedBuyer.php 20-56.

**Pro plugin:**
- **Admin:** ManualReviews.php 166, 189, 236, 265.
- **Moderation and incentives:** Plus.php 39-42, 52, 84, 132; CouponReward.php 43, 47, 93.
- **Import and external:** ExternalReviews.php 858; ProImporter.php 31, 519.
- **Other:**
  - UnlimitedCriteria.php 34;
  - BulkCampaign.php 159;
  - RestApi.php 145-157;
  - the `review_created` listeners at VideoReviews 36, Reputation\Plus 42, Webhooks 46, Ai 54.

**Imprecise, but the design is unaffected:**
- RR-11 cites `DashboardPage::order_submenu()` at 111-133; the method starts at :98.
- RR-21 cites Plus.php 52-75; RR-01 cites 52-73.

## Findings

### 1. MAJOR: RR-15 §6 and Pro RR-01 §6 have different non-rating reason slugs
- **Defect:** PRD-00 §6 says Pro keeps "the slugs identical". The two lists differ:
  - RR-15 `non_rating_reasons()`: `spam`, **`abusive`**, `personal_info`, `off_topic`, `policy`;
  - Pro RR-01 `IncentiveLabel::REJECT_REASONS`: `spam`, **`offensive`**, `personal_info`, `off_topic`, `policy`, plus `spam_filter`.
- **Consequences:**
  - Once Pro switches to the free list (`NDVR_API >= 8`), a review stamped `_ndvr_reject_reason = offensive` matches no reason. `check_due()` then treats it as reasonless and issues the coupon.
  - `spam_filter` is not in the free list either.
  - If Pro instead adds `spam_filter` through `ndv-reviews/non_rating_reasons`, it appears in RR-15's "Discard edit" select, where it is meaningless.
- **Evidence:**
  - `rosette-reviews/.agents/prd/RR-15-my-account-reviews.md:138`;
  - `rosette-reviews-pro/.agents/prd/RR-01-incentive-disclosure.md:90, 95`;
  - `PRD-00-conventions.md` §6.
- **Fix, RR-15 §6 (line 138):** replace the slug list with: "`spam`, `offensive`, `personal_info`, `off_topic`, `policy`" (labels unchanged: "Offensive or abusive" keeps slug `offensive`). Also add: "These five slugs are frozen; Pro RR-01 copies them verbatim."
- **Fix, Pro RR-01 §6 "Rejection reasons":** add "At `NDVR_API >= 8` the select options and the check in `check_due()` use `array_keys( non_rating_reasons() )` plus the Pro-local `spam_filter`. `spam_filter` is never added through `ndv-reviews/non_rating_reasons`. Below 8, the constant is used, with slugs identical to RR-15."

### 2. MAJOR: Pro RR-17's trusted-email copy contradicts RR-22 §8 on guest `list_link`
- **Defect:** RR-22 §8 now trusts guest `list_link` reviews (`comment_author_email` is the list address the link was mailed to, from RR-09 Landing). It says "Pro RR-17's reply rule (which copies this list) follow automatically". Pro RR-17 still says the opposite, so the "one rule" in PRD-00 §6 forks.
- **Evidence:**
  - RR-22 line 136;
  - Pro RR-17 lines 69 ("neither are guest `list_link` reviews … RR-22's rule trusts a guest's email only for `magic_link`") and 90 ("Guest `list_link` reviews are not in RR-22's list either, so they get no notice").
- **Fix, Pro RR-17 §5 (line 69):** "Guest reviews with a typed email (`onsite`, `form`) are never notified. Guest `magic_link` and `list_link` reviews are notified at `comment_author_email`, which came from the order, account or list record (RR-22 §8). Signed-in reviewers of any interactive source are notified at their account email."
- **Fix, Pro RR-17 §7 (line 90):** delete the `list_link` sentence and add a bullet "guest `list_link`: `comment_author_email` (RR-22 §8)". Add an AC: "a guest `list_link` review gets the reply notice at the list address."
- Prefer "applies `ReplyNotice::trusted_email()` = RR-22 §8, not a copy" so the two can't drift again.

### 3. MAJOR: RR-22 §8 checks ownership on the edit-token page only for `magic_link` guests
- **Defect:** §8 makes guest `list_link` reviews inviteable, but the token page's ownership check and the `$auth` choice name only magic-link reviews. A guest `list_link` review therefore has no stated check:
  - step 4: "`email_matches( $row, comment_author_email )` for magic-link reviews, or `customer_id` equals the comment's `user_id`";
  - save step 5: "otherwise `[ 'email' => … ]` (magic-link review…)".

  AC7 doesn't test `list_link` either.
- **Evidence:** RR-22 lines 136, 142, 149, 184.
- **Fix, RR-22 §8 step 4:** "4. when the token's `customer_id` is set, it equals the comment's `user_id`; otherwise (guest `magic_link` or `list_link` review) `email_matches( $row, $comment->comment_author_email )`."
- **Fix, step 5:** replace "(magic-link review; …)" with "(guest `magic_link` or `list_link` review; …)".
- **Fix, AC7:** "A guest `onsite` review that is resolved has no invite action. Signed-in, guest `magic_link` and guest `list_link` reviews have it, and the `list_link` invite link saves a pending edit."

### 4. MAJOR: RR-22 §8 invite lock lets two invites go out (time-of-check to time-of-use race)
- **Defect:** the order is "re-check every precondition (including not invited)", then `add_option( 'ndvr_invite_lock_'.$id )`. The winner writes `_ndvr_recovery_invited_at`, sends, and **deletes the lock**.
  1. Request B passes its precondition check before A writes `invited_at`.
  2. B reaches `add_option` after A has deleted the lock.
  3. B's `add_option` succeeds, and B sends a second invitation.

  That breaks "sent at most once per review" (§2, a legal guardrail) and AC6.
- **Evidence:** RR-22 line 132.
- **Fix:** replace the lock sentences in §8 with:
  > "Sending is claimed with `add_option( 'ndvr_invite_lock_' . $id, time(), '', false )`. Only the request whose `add_option` succeeds continues, and it then re-reads `_ndvr_recovery_invited_at` after `wp_cache_delete( $id, 'comment_meta' )`. If the meta is set, it stops without sending. Otherwise it writes `_ndvr_recovery_invited_at`, sends, and keeps the lock option (it's deleted with the review's other data on uninstall, via the F2 registry prefix `ndvr_invite_lock_`)."
- **Fix, AC6:** add "Two dispatches where the second's precondition check runs before the first writes `invited_at` (simulated by calling the post-lock path twice) send exactly one email."

### 5. MAJOR: RR-22 §5/§8 makes a used edit token and the PRG landing contradict AC8 and the success copy
- **Defect:** RR-22 puts the `edit` branch at "`'edit' === $row->type` before `pending_products()` (:134)". But `$row = resolve( $raw )` (Landing.php:130) is null for a `used` or expired token. A consumed edit token therefore falls through to the existing `lookup()`/`used` path (Landing.php:141-146), which renders the multi-product thank-you landing with no products. This breaks two things:
  1. AC8 "Reusing the link shows 'This link has already been used.'" can't pass.
  2. The PRG GET after a successful save (step 6) always sees a `used` token. The spec gives no way to show "Thanks. Your changes will show after the store checks them." instead of the "already used" text.
- **Evidence:** Landing.php:124-146; RR-22 lines 71, 88, 148-150, 185.
- **Fix, RR-22 §5 `Landing::maybe_render()`:**
  > "Before `resolve()`, `$any = lookup( $raw )`. When `$any && 'edit' === $any->type`, the edit branch handles every state and returns:
  > - `active` and unexpired: the form;
  > - expired: the existing expired page;
  > - `used`: the thank-you text when the request carries `ndvr_edit_saved=1` and the transient `ndvr_edit_saved_{token_id}` (60 s, set by the save just before its redirect) exists, which it then deletes; otherwise 'This link has already been used.'"
- **Fix, §8 save step 6:** "PRG redirect to the same URL plus `ndvr_edit_saved=1`." Register the transient prefix in the F2 registry.
- **Fix, AC8:** add "the redirect target shows the thank-you text once; loading it again shows 'This link has already been used.'"

### 6. MAJOR: RR-15 §3/§5/§8 nests the "Customer edit waiting" form inside the edit form
- **Defect:** RR-15 says the box sits "above the edit form, as its own form" (nonce `ndvr_pending_edit_{id}`). §5 renders it "via `ndv-reviews/moderation_edit_fields` from RR-11", but RR-11 §5 fires that action "before the Photos row (~Page.php:535)", inside `<form method="post">` (Page.php:485–554) and inside its `<table class="form-table">` (:489–548).
  - Browsers drop a nested `<form>` tag, so "Apply edit" or "Discard edit" would submit the outer edit form. The `ndvr_edit_save` field and nonce `ndvr_edit_review` would then reach `save_edit()`, and `PendingEdits` would never run.
  - At best this is a no-op. At worst the moderator saves the live review.
- **Evidence:** Moderation/Page.php:485, 489, 535, 548, 554; RR-11 line 58; RR-15 lines 59, 94, 177.
- **Fix, RR-15 §5 `Moderation\Page` bullet:** "the pending-edit box renders on a new action `ndv-reviews/moderation_edit_before_form` (WP_Comment), fired in `render_edit()` immediately before the edit `<form>` (Page.php:485). This PRD adds it; RR-22 and RR-21 boxes that save with the main form keep using `moderation_edit_fields`."
- **Fix, §6 Actions:** add the action.
- **Fix, AC7:** add "the rendered edit screen has no `<form>` element nested in another (DOMDocument check), and 'Apply edit' posts only the `ndvr_pending_edit_{id}` nonce."

### 7. MAJOR: RR-15 §6 `apply_pending_edit()` can publish a revision the moderator never saw
- **Defect:** "Status `1`: stores `_ndvr_pending_edit` (replacing an earlier one)". The race:
  1. A moderator opens edit A.
  2. The customer submits edit B, from My Account or an RR-22 invite.
  3. The moderator clicks Apply.
  4. `apply_pending_edit( $id )` reads the current meta and publishes B, which no person checked.

  That defeats F8.1 ("the moderator applies or discards it") and the "Apply … by the same rules you use for new reviews" promise.
- **Evidence:** RR-15 lines 130, 132-136, 177.
- **Fix, RR-15 §6:**
  - Change the signature to `apply_pending_edit( int $id, string $expected_submitted_at ): true|WP_Error` and `discard_pending_edit( int $id, string $reason, string $expected_submitted_at )`.
  - Add: "Both refuse with `WP_Error( 'ndvr_edit_changed', __( 'The customer changed this edit again. Check the new version.', 'rosette-reviews' ) )` when `_ndvr_pending_edit['submitted_at']` differs from the value rendered in the box (hidden field `ndvr_pending_submitted_at`)."
- **Fix, §8 admin bullet:** add "the form carries `ndvr_pending_submitted_at`."
- **Fix, new AC:** "Render the box for edit A, store edit B, then POST Apply with A's `submitted_at`: `ndvr_edit_changed`, `comment_content` unchanged, B still pending."
- **Fix, RR-22 §4:** `apply_pending_edit()` callers pass the value too.

### 8. MAJOR: RR-21 §8 and RR-03 specify different text and conditions for the `integrity` sentence, and RR-03 describes a listener RR-21 no longer has
- **Defect:** two PRDs specify the same transparency key differently. One of them will ship wrong.
  - **RR-21 §8:**
    - text: "We check new reviews for signs of fake reviews, such as many reviews from one connection in a day. A review that shows several signs waits for a person to check it before it appears. The star rating is never part of this check.";
    - shown when `integrity_enabled` and `integrity_hold_high` (both on by default);
    - AC16 tests that.
  - **RR-03 table:**
    - text: "Reviews our automatic checks mark as high risk are held for a manual check before they appear.";
    - shown only when `integrity_hold_high` **and** a rule can auto-approve, so never in free alone;
    - it also says "Its `should_approve` listener only holds reviews, so it also sets the fact `auto_approve` back".
  - Since round 2, RR-21's hold is inline in `create()`, not a `should_approve` listener (RR-21 §5 "The hold is inline, not a filter"). So RR-03's `auto_approve = has_filter( 'should_approve' )` reasoning about RR-21 is stale.
  - RR-14 already uses the "canonical here, RR-03 references it" pattern. RR-21 doesn't.
- **Evidence:** RR-21 lines 67, 111, 124, 166; RR-03 lines 60, 82.
- **Fix, RR-21 §8:** prefix the sentence bullet with "**Canonical (RR-03 references this text and adds no copy of its own).**" Keep RR-21's text and condition (`integrity_enabled && integrity_hold_high`). It is true in free alone, because every free review waits.
- **Fix, RR-03 row "RR-21 integrity signals":** "key `integrity` | the text is canonical in RR-21 §8 | `integrity_enabled` and `integrity_hold_high` (RR-21 §8)".
- **Fix, RR-03 `moderation` row:** delete "A feature whose listener only holds reviews (RR-21) sets the fact back to false." and "Its `should_approve` listener only holds reviews, …". RR-21 adds no `should_approve` callback, so it doesn't affect `has_filter()`.

### 9. MAJOR (small fix): RR-14 §6 leaves report meta on surviving reviews at uninstall
- **Defect:**
  - Reports can be filed on **any** approved review, including native WooCommerce reviews and imports, which survive opt-in uninstall (CLAUDE.md §4).
  - RR-00 F2 step 4 sweeps only the `_ndvr_*` comment-meta keys **listed in the registry** from surviving reviews, and "every new feature appends to it".
  - RR-14's registry entry lists only the table and the `ndvr_rl_` transients. `_ndvr_report_count` and `_ndvr_report_held` therefore remain on native reviews after "remove data on uninstall". RR-11 and RR-23 list their comment meta for exactly this reason.
- **Evidence:** RR-14 lines 83-85, 98; RR-00 F2 (order of work, step 4); RR-11 line 93.
- **Fix, RR-14 §6 "Uninstall registry (F2)":** append "comment meta `_ndvr_report_count` and `_ndvr_report_held` in the registry's comment-meta group (native and imported reviews survive uninstall and may carry them)".
- **Fix, AC:** add to a new AC 13b "the uninstall dry run lists both meta keys".

## Minor, non-blocking notes (no action needed for prd-ok)
- **RR-21 and RR-23 disagree on token reviews.** RR-21's `unverified` signal reads `is_verified()` before the insert. For order-token landing reviews, Landing forces `_ndvr_verified = 1` only after `create()` (Landing.php:311-313). RR-23 §5 already treats `magic_link` with an `order_id` as verified; RR-21 doesn't. The impact is rare, because `is_verified()` with the order's billing email is normally true. Consider making both read one "token-verified" data key.
- **RR-15 `for_account()` query count.** It should state that pool ids are de-duplicated across the 50 orders before `has_reviewed()` runs. The product_id => date map implies it.
- **The RR-15 `edit_u{id}` key also contains the IP hash** (F7 key shape), so the limit is per user and IP. That's harmless.
- **RR-22 edit tokens' `products` JSON (`{"comment_id":ID}`)** is safely ignored by `token_products()`/`pending_products()` (Landing.php:174-206 only read entries with `id`). It's fine as specified.
- **Free RR-15 reads the Pro meta key `_ndvr_admin_created`** (written at Pro ManualReviews.php:189). It's belt-and-braces, because source `admin` is already non-interactive. The key should be listed in CONTRACTS as free-documented, or the check should be dropped.
