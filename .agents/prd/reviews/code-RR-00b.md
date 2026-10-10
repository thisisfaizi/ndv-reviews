# Code review: RR-00b free extension points for Pro

Reviewer: independent senior WP/WC code review, 2026-10-10. Read-only: diff against `D:/.devcache/patches/pre-RR-00b/`, grep and `php -l`. No Playground runs.
Spec: `.agents/prd/RR-00b-extension-points.md` rev 4. Rules: `PRD-00-conventions.md`.
Runtime evidence taken as given: rr-00b 54/54, rr-09 62/62, rr-00 44/44, core-flows 49/49 (PHP 8.3, SQLite, WP 7.1.3, WC 11.2.0).

## Verdict: CHANGES REQUESTED

- **Free MAJORs (block this merge):** M1, M2 and M4.
- **M3 needs an owner decision:** either the read fix, or an explicit release-sequencing rule.
- **Pro MAJORs P1–P3** don't block the free merge. They block Pro RR-01, the first change that sets `_ndvr_incentive_offered`. They are listed here because the brief asks for every list path that would miss the disclosure.

`php -l` is clean on every touched PHP file and template. No PHP 8-only syntax was found (no `?->`, `match`, `str_contains`, typed properties or union types).

---

## MAJOR (free)

### M1. The Recent Reviews widget never shows the incentive disclosure
- **Where:**
  - `includes/Integrations/Widgets/RecentReviewsWidget.php:51-58` builds its own `<li>` cards (stars, text, `— author`) and fires no badge action.
  - Pro `Social/AutoPoster.php:208-225` (`[ndvr-social-feed]`) reuses `Widgets::recent()` and has the same gap.
- **Failure:** a review carrying `_ndvr_incentive_offered = received` shows the pill on the reviews tab, but not in the classic "Recent reviews" sidebar widget. PRD-00 §2.17 and RR-00 F5 require the disclosure on every public rendering, and E9 says it must survive with Pro removed. This widget is free code, so free alone breaks the rule.
- **Fix:** inside the author div, fire the compact action. Use `marquee_author_badges`, not `review_author_badges`, so Highlight's block stays out:
  ```php
  echo '<div class="ndvr-recent-author">&mdash; ' . esc_html( $review['author'] );
  do_action( 'ndv-reviews/marquee_author_badges', $review );
  echo '</div>';
  ```
  - `enqueue( 'stars' )` already loads `ndvr-display`, so the `.ndvr-badge` CSS is present.
  - Add an AC12 check for `the_widget( RecentReviewsWidget::class )`.
  - Also consider a public static `Display\ReviewBadges::html( array $review ): string` (CONTRACTS) as the one-call helper for any custom card, free or Pro.

### M2. `Pool::orphans()` applies LIMIT before its PHP filter, so the Tools action can move nothing
- **Where:** `includes/Reviews/Pool.php:77-110`.
  - The SQL selects every review stamped `_ndvr_pooled_from`, ordered by `comment_ID ASC LIMIT $limit*5` (:91-93).
  - The "is this origin still pooled?" test (`resolve_id( X ) === X`) runs afterwards in PHP.
  - Every review on an *active* pool also matches `comment_post_ID <> meta_value`, so active-pool rows fill the window.
- **Failure:** Pro group G1 is active with 1,200 stamped reviews. Group G2 is dissolved later, and its reviews have higher ids.
  - The Tools render calls `orphans( 1000 )` (ToolsPage.php:254), which scans 5,000 rows. The card shows "N reviews are stored on another product".
  - The POST calls `orphans( 200 )` (ToolsPage.php:139), which scans the first 1,000 rows. All of them are still pooled, so `restore( [] )` runs and the notice says "Moved 0 reviews back". The card stays.
  - With more than 5,000 active-pool reviews, the card never appears at all.
- **Fix:** select by origin, not by comment.
  ```php
  // 1. Distinct origins (bounded by the number of products, not reviews).
  $origins = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT meta_value FROM {$wpdb->commentmeta} WHERE meta_key = %s", self::POOLED_FROM_META ) );
  // 2. Keep origins that no pool maps any more (and optionally == $origin).
  $free = array_filter( array_map( 'absint', $origins ), static function ( $x ) use ( $origin ) { return ( ! $origin || $x === $origin ) && PostTypes::is_reviewable( $x ) && self::resolve_id( $x ) === $x; } );
  // 3. Ids, limited in SQL.
  "SELECT c.comment_ID FROM {$wpdb->commentmeta} cm INNER JOIN {$wpdb->comments} c ON c.comment_ID = cm.comment_id
   WHERE cm.meta_key = %s AND cm.meta_value IN (%s,…) AND c.comment_post_ID <> cm.meta_value ORDER BY c.comment_ID LIMIT %d"
  ```
  - Bind the origins as strings, because `meta_value` is longtext.
  - The Tools card can then show an exact `COUNT(*)` from the same WHERE.
  - This also removes the per-row `resolve_id()` and `is_reviewable()` work, up to 5,000 rows on every Tools render. With Pro active, each row costs a `Pooling::resolve()` → `wc_get_product()` call (see m6).
  - Add a test with more than `limit*5` active-pool stamped rows ahead of an orphan.

### M3. Pro's existing groups: reviews stored on a member post disappear from the storefront
- **Where:** `includes/Reviews/ReviewQuery.php:61-65` (`post_id => $pool_id`), together with Pro `Reviews/Pooling.php:51-55`. Pro already ships `ndv_reviews_pro_groups` (child → master).
- **Failure:** the reviews affected are member-stored ones:
  - reviews written on a member before the group existed;
  - every native no-JS review before this build (no remap);
  - WooCommerce REST-created reviews (`WP_REST_Comments_Controller` uses `wp_insert_comment()`, so there is no `preprocess_comment` remap).

  Before RR-00b they were listed on the member's page, under the master's stars. After RR-00b the member page lists only the master post's comments, so these reviews are listed nowhere for that product. They don't appear in the master's list or aggregate either.
  - Nothing moves them: `orphans()` only sees stamped reviews, and move-on-join is Pro RR-19, which isn't built.
  - The build already admits these reviews exist: `Reviewable::has_reviewed()` deliberately checks `[ product, pool ]`.
  - §11 "Old Pro with this free" doesn't mention this regression.
- **Fix:** owner picks one.
  - (a) Read both posts: `post__in => array_unique( [ $product_id, $pool_id ] )` in `paginate()`, and the same pair in `comment_ids_with_media()` and `ReviewTags`. The aggregate stays the pool's. This is a 2-post read, not the multi-member pool the non-goal excludes.
  - (b) Make it a release rule that Pro RR-19's move-on-join migration (stamping `_ndvr_pooled_from`) ships in or before the first Pro build that runs with free `NDVR_API` 4. Put a readme/changelog note on the free side.

  If no Pro build with groups has reached customers, (b) is enough and this drops to MINOR. Please state which applies.

### M4. `.ndvr-badge` collides with Pro's floating aggregate badge class
- **Where:**
  - Free: `assets/css/display.css:389-400` (`.ndvr-badge`, specificity 0,1,0).
  - Pro: `assets/css/widgets.css:9-13` and `:73-84` (`.ndvr-badge` with padding 9px 18px, box-shadow, gap, weight 700), and `includes/Widgets/Catalog.php:307` (`<div class="ndvr-badge ndvr-badge-float">`, `[ndvr-badge]`, often placed site-wide).
- **Failure:** the properties don't overlap, so each rule leaks into the other's element regardless of load order:
  - The incentive pill on a page with the floating badge gets Pro's drop shadow and gap.
  - Whichever file loads last also sets the padding, background and weight.
  - Pro's floating badge gets `font-size: .733em`, so its text shrinks by a quarter.
  - Because `.ndvr-badge` is being frozen in CONTRACTS (both repos) and Pro RR-02 will build on it, this must be settled now.
- **Fix:**
  - Free: scope the rule to the card contexts, `.ndvr-review-author .ndvr-badge, .ndvr-recent-item .ndvr-badge { …; box-shadow: none; gap: 0; }`. The marquee rule is already scoped (`.ndvr-marquee-name .ndvr-badge`). Rebuild `.min`.
  - Paired Pro task (CONTRACTS mirror): rename Pro's aggregate badge class to `ndvr-aggregate-badge` and keep `ndvr-badge-float`.
  - Alternative: rename the free contract class to `.ndvr-pill` before anything consumes it.

## MAJOR (Pro, blocks Pro RR-01, not this free merge)
Pro must not ship `_ndvr_incentive_offered` (RR-01) until each of these prints the free pill. Use `marquee_author_badges` for compact cards, or the helper suggested in M1.

- **P1. `Widgets/Catalog.php` builds its own cards and fires no badge action:**
  - carousel `:202-205` (figcaption);
  - wall `:262-263`;
  - video carousel `:337-340`;
  - avatar carousel `:~368`;
  - auto slider `:~397`.
  - `[ndvr-all-reviews]` (`:523-540`) is fine: it uses `review-list.php`.
- **P2. Elementor "Elementor template" card source.**
  - `Elementor/GridRenderer.php:93-96` renders through `TemplatePicker` and the Parts, and no Part fires a badge action (`Widgets/Parts/AuthorPart.php:96-109`, `VerifiedPart.php:97-109`).
  - Prebuilt mode (`:83-92`, `review-item.php`) is fine.
  - The merchant's template may omit AuthorPart, so print the pill unconditionally in the `.ndvr-egrid-item` wrapper. Also have AuthorPart fire `review_author_badges`.
- **P3. Social cross-posting.** `Social/AutoPoster.php:111` (text posted to Facebook or a webhook) and `Social/CardRenderer.php:79-87` (share image) republish the endorsement with no disclosure. Append the incentive label text when `ReviewQuery::incentive( $id )` is not empty.
- **Pro RR-01 note:** reviews already rewarded carry only `_ndvr_rewarded` (`CouponReward.php:117`). Backfill them to `_ndvr_incentive_offered = received`, or old rewarded reviews stay undisclosed.

---

## MINOR (referenced as m1–m14 by list number)

1. **Native redirect drops core's moderation preview args.**
   - Where: `Forms/ReviewForm.php:164-172`.
   - Cause: `wp-comments-post.php:57-68` builds `$location`, including `redirect_to` and `?unapproved=&moderation-hash=`, before `comment_post_redirect`. The override replaces the whole URL.
   - Failure: a guest whose pooled native review is held, and who didn't consent to cookies, never sees "awaiting moderation". A theme's `redirect_to` is also ignored.
   - Fix: keep the query args, `add_query_arg( array_intersect_key( wp_parse_args( wp_parse_url( $location, PHP_URL_QUERY ) ), array_flip( [ 'unapproved', 'moderation-hash' ] ) ), get_permalink( $origin ) ) . '#comment-' . $id`.
   - Then only rewrite when `$_POST['redirect_to']` is empty.
2. **Native pooled posts are verified against the pool, not the product reviewed.**
   - Cause: WooCommerce's `add_comment_purchase_verification` (`class-wc-comments.php:514-520`, `comment_post` priority 10) checks `wc_customer_bought_product( …, $comment->comment_post_ID )`, which is now the pool A. Free's `WooNative::run()` (`Importers/WooNative.php:80`) does the same when it later backfills `_ndvr_verified`.
   - `create()` verifies against the origin.
   - Failure: someone who bought A but not B reviews B without JS and is shown as "Verified buyer" on B. Someone who bought B loses the badge.
   - Fix: in `stamp_native_pooled()` (priority 20, after WooCommerce), recompute `verified` against `$this->native_origin`. In WooNative, use `_ndvr_pooled_from` when present.
   - It's rated MINOR only because it needs a no-JS post on a pooled product. It is a positive over-claim, so fix it in this round.
3. **The pill can be removed through the documented services filter.**
   - Where: `Plugin.php:483`. `ReviewBadges` sits inside the array passed to `ndv-reviews/services`, so one `array_filter` drops the disclosure. `remove_action` can't reach it, because the instance is anonymous; this filter can.
   - Fix: register it after the filter: `( new \NdvReviews\Display\ReviewBadges() )->register();` outside `$services`.
   - Residual suppression paths, documented or acceptable:
     - a `review_items` filter that strips both `incentive` and `id`;
     - an `incentive_label` that returns punctuation;
     - an old `marquee.php` override (known, per §11).
4. **A cleanup job is scheduled for every spam or trash move, even with no media.**
   - Where: `Moderation/Actions.php:90-117`. A spam wave or bulk "mark as spam" creates one Action Scheduler action plus two `as_has_scheduled_action` queries per comment.
   - Fix: return early unless `SELECT 1 FROM review_media WHERE comment_id = %d LIMIT 1` finds a row. Comments inserted as spam never transition, and `resweep_held_media()` already covers them.
5. **`resweep_held_media()` stalls past 200 held reviews.**
   - Where: `Moderation/Actions.php:165-190`. It always takes the lowest 200 ids. Once those are scheduled, each hourly run re-checks the same 200 (about 400 AS queries) and schedules nothing new until they are cleaned seven days later.
   - Failure: with 1,000 held reviews after a reactivation, the last ones wait about five retention periods, not "one retention period plus one hour" (§6.3).
   - Fix: keyset-paginate with a stored cursor (`m.comment_id > %d`, a transient), wrapping to 0 when the result is empty.
6. **`orphans()` and the Tools render cost.** This goes away with the M2 fix. Until then, every Tools page view runs a 5,000-row join plus a `wc_get_product()` per row when Pro is active.
7. **`Pool::restore()` queries descendants per review, not per batch** (`Pool.php:119-187`).
   - Cost: about 200 × (1 + depth) `get_comments` calls, plus `get_comment`, `get_comment_meta` and `update` per member, in one POST. §10 promises "one `parent__in` query per reply level per batch".
   - Fix: collect the batch per pool post and run one `parent__in` per level. Prime `update_meta_cache( 'comment', $ids )` first.
   - `restore()` also doesn't re-check `resolve_id( $origin ) === $origin`. Pro or third-party callers that pass still-pooled ids make reviews vanish from both lists. Add the guard, or document that ids must come from `orphans()`.
8. **Held-media cleanup can delete library items attached through the new API.**
   - Where: `Actions::cleanup_held_media()` → `delete_photo_if_unused()` (:236-255) protects only `_ndvr_admin_created` reviews.
   - Cause: `attach_media()` is now public and accepts any attachment, with origin `api` or `import`. If an importer or add-on attaches an existing media-library item (a product gallery image, a shared video) and the review is then spammed, the file is permanently deleted seven days later. Before E3 this needed a permanent delete; now it's automatic.
   - Fix: mark plugin uploads (`Upload` sets post meta `_ndvr_review_upload`) and delete only those. Or in `attach_media()`, when `origin` isn't `create`, write `_ndvr_admin_created`-equivalent comment meta.
9. **Privacy: `question_votes` isn't covered.**
   - Where: `Installer.php` `question_votes` has `user_id` and `ip_hash`, and Pro `QandA/QuestionRepository.php:332` writes them. `qa_export` and `qa_erase` (`Privacy.php:283-397`) skip the table.
   - Fix: in `qa_erase`, run `UPDATE question_votes SET user_id = NULL WHERE user_id = %d`. NULL doesn't collide in `uniq_qvote`. Optionally export a vote count.
   - The answers export also lacks the product or question it answers. Add "Question" from a join, for a readable export.
10. **Category lists aren't pool-aware.** `ReviewQuery.php:92` sets `post__in` to the category's product ids, so pooled reviews of an in-category member stored on an out-of-category pool are missing. Map through `Pool::resolve_id()` and `array_unique`. This is pre-existing, but E4's goal is "pool-aware reads".
11. **Nit:** `Installer::missing_columns()` (`Installer.php:406`) builds `$wpdb->prefix . NDVR_TABLE_PREFIX . $suffix` instead of `Db::table()` (PRD-00 §2.3). The same file does this at :431, so it's consistent locally. It is identical today.
12. **Privacy matching depends on collation.**
    - Where: `qa_export()` and `qa_erase()` match `author_email = strtolower( trim( $email ) )`.
    - This finds mixed-case rows only under MySQL's default `_ci` collations. On a `_bin` or case-sensitive collation, or on SQLite, a mixed-case address that Pro RR-17 stores would be missed, so the erasure is incomplete.
    - Fix: state in CONTRACTS that `questions.author_email` is stored lowercased, and have Pro RR-17 store it that way. Don't wrap the column in `LOWER()`, which would defeat `email_idx`.
13. **WP 6.0 support for `'status' => 'any'` is unconfirmed.**
    - Where: `Pool::descendants()` relies on `'status' => 'any'`. The value isn't in `WP_Comment_Query`'s documented `status` list, and I could only confirm the code path on 7.1.3.
    - Fix: use `'status' => array( 'all', 'spam', 'trash' )`, which works on every version that accepts status arrays (4.4+).
14. **Nit:** the Tools card count is capped at `orphans( 1000 )`, so it says "1,000 reviews" when there are more. Fixed by M2's `COUNT(*)`.

## Out of scope (seen in the diff, not reviewed against RR-00b)
- `Requests/Scheduler.php`: campaign source validation and dedupe, legacy-row reuse, the erased-email send and retry guard, and `wp_doing_ajax()` in `ensure_recover_scheduled`.
- `Admin/DashboardPage.php:557`: the new "Sent" stat.

These are RR-09 follow-ups bundled into this patch. I checked only that the `$order` null case returns before the campaign dedupe dereferences it (it does, Scheduler.php:183-185). Record them under RR-09 in LOG.md, not RR-00b.

---

## Checked and fine

**MySQL vs SQLite**
- `c.comment_post_ID <> cm.meta_value` is a bigint-vs-longtext comparison. MySQL compares numerically, so numeric strings work, and `''` becomes 0 and is then dropped by `absint`. The `= %s` origin filter does a string compare on the value as `update_comment_meta` stored it, which is correct.
- `COALESCE( MAX( position ), -1 ) + 1` is portable.
- `SELECT DISTINCT m.comment_id … ORDER BY m.comment_id` orders by a selected column, so it is ONLY_FULL_GROUP_BY-safe.
- `GROUP BY type` in `column_media` is fine.
- `email_idx` on `varchar(191)` fits the 767-byte utf8mb4 prefix.
- `questions.user_id` and `answers.user_id` are `DEFAULT NULL`, so `SET user_id = NULL` won't fail in strict mode.

**Pool invariants**
- `resolve_id()` idempotence is documented in the class and filter docblocks.
- `paginate()` checks `is_viewable()` on the requested id and queries the pool. `JsonLd`'s double resolve is a no-op.
- The remap skips `is_admin()` and replies, and only targets `Pool::resolve_id()` of a product.
- `stamp_native_pooled` runs after WooCommerce's `add_comment_rating` (priority 1) and recounts the pool.
- `restore()` moves held, spam and trashed replies. `'status' => 'any'` is honoured by `WP_Comment_Query` on 7.1.3 (the "any overrides other statuses" branch, class-wp-comment-query.php:568), and `'type' => ''` means all types. On WP 6.0 this is unconfirmed: the value isn't in the documented `status` list (see m13).
- `restore()` recounts both posts, with `wp_update_comment_count()` and `recalc_product()` once per post.
- The Tools batch is 200, recomputed server-side from `orphans()`, not posted ids.
- `on_status_change` resolves the pool (PRD-00 §2.4).
- `for_order()` keeps one id per pool and returns `array_values()`, so callers that iterate values are unaffected.
- `has_reviewed()` matches PRD-00 §6 exactly: two counts, email OR user, `post__in` = product + pool.

**Security**
- Tools restore: `check_admin_referer( ToolsPage::NONCE )`, then `current_user_can( Caps::manage( 'tools' ) )`, then work (ToolsPage.php:114-121). No ids are taken from the request.
- Size guard:
  - `request_too_large()` reads only the emptiness of `$_POST`/`$_FILES` and `absint( CONTENT_LENGTH )`.
  - It returns 413 before the nonce check, does no other work, and is harmless when spoofed.
  - The JS sends `action` in the URL. The `.min` builds contain `action=`.
  - Both scripts display `res.data.message` from the 413 JSON.
- Landing handle filters print only registered handles (`wp_style_is`/`wp_script_is( …, 'registered' )`).
- All new SQL is prepared. Table names come from `Db::table()`, except the m11 nit.

**Media type handling**
- `media()`/`media_bulk()` default to `image`. Non-image rows have `thumb` `''` and the attachment URL. The `<img>` fallback is image-only.
- Moderation edit (`'any'`, video as a link), the list column ("1 photo, 1 video"), the dashboard KPI (`type = 'image'`), the CSV/JSON `videos` column, and the privacy Photo/Video labels match §6.2.
- Pro readers:
  - `Elementor/Widgets/Parts/PhotosPart.php:113-128` and `Widgets/Catalog.php:231` read `$review['media']`, which is now images only. That's an improvement: Pro ManualReviews video rows no longer render inside `<img>`.
  - `Incentives/CouponReward.php:216-223` counts all types, which is right for "has media".
  - `Moderation/Plus.php:41` (1-argument `review_media_status`) is unaffected.
  - Pro `ManualReviews.php:298-327` direct writes still work.

**Backward compatibility for Pro listeners**
- `TopReviewer.php:36` and `Highlight.php:43` stay at priority 10, after the pill at 5.
- Highlight doesn't reach the marquee (separate action).
- `review_query_args` and `review_items` listeners still get `product_id` = the requested id, plus `pool_id`.

**Pill reach (free)**
- `ReviewBadges` is registered unconditionally, so it fires in the admin-ajax `ndvr_list_reviews` path and the Elementor editor.
- `review-list.php` → `review-item.php` is used by the reviews tab, `[ndvr-reviews]`, the block, the free Elementor Reviews widget and Pro prebuilt grids.
- `marquee.php` is used by the shortcode, block, widget and Elementor marquee.
- The pill falls back to the meta when a view-model lacks `incentive`.
- There is no per-card query, because `WP_Comment_Query` primes comment meta.
- There is no `show_*` switch. An empty or whitespace label falls back to the default.

**E10 privacy**
- Export and erase run on the last page only.
- The email parts are gated on `Installer::is_current( V_QA_EMAIL )`. The name and user parts work on v2.
- The readme note is present.
- `required_columns()` stops a v5 being recorded when dbDelta skipped the ALTER.

**PHP 7.4 / WP 6.0:** nothing newer is used. `wp_convert_hr_to_bytes`, `wp_get_comment_status` and the Action Scheduler functions are all available. The one exception is the undocumented `'any'` comment status (m13).

**Uninstall:** the `Uninstall::registry()` additions are present (`_ndvr_pooled_from`, `_ndvr_incentive_offered`, `ndvr_media_cleanup`), and `Deactivator` unschedules every registry hook.

**Contracts:** CONTRACTS.md (free) and the Pro mirror (`rosette-reviews-pro/.agents/CONTRACTS.md:91-98`) both record level 4. `NDVR_API` is 4 and `NDVR_DB_VERSION` is 5. `NDVR_VERSION` is untouched.

## AC15: byte-identical output for an unpooled product (reasoned, no snapshot)
With no add-on and no pool, I expect the reviews tab list, the AJAX list, the summary and the JSON-LD to be byte-identical:
- `post_id` = the product id, because the pool is identity.
- The verified text comes from the filter with the same default string.
- `review_author_badges` has one new listener that prints nothing without the meta.
- Image rows build the same `url`/`thumb`.
- `media_bulk` adds `AND type = 'image'`, and every row free ever wrote is `image`. `type` is `NOT NULL` with no default, and both free and Pro always write it.

What changes:
- Additive array keys (`type`, `incentive`, `incentivized`, `$args['pool_id']`). These appear in output only where a view-model is JSON-encoded, which free never does; the Pro REST API gains keys.
- `marquee.php` gains whitespace and PHP-block bytes but no markup. Marquee is outside AC15.
- The intended admin and export fixes (the moderation Media column text, the CSV `videos` column, the image-only photo KPI) and the out-of-scope Dashboard "Sent" stat.

A snapshot diff is still recommended before tagging, because AC15 asks for one.

## AC status (by code reading)

| AC | Status |
|---|---|
| 1 | met |
| 2 | met |
| 3 | met. The resweep part works but stalls past 200 (m5) |
| 4 | met |
| 5 | met |
| 6 | met. Redirect args lost (m1); verification (m2) |
| 7 | met. Fires last, after `clear_transients` |
| 8 | met only below `limit*5` active-pool rows (**M2**) |
| 9 | met |
| 10 | met (RR-09 code) |
| 11 | met |
| 12 | met for the listed surfaces. The free Recent Reviews widget is uncovered (**M1**) |
| 13 | met |
| 14 | met |
| 15 | reasoned met for an unpooled product. Pooled products under current Pro groups regress (**M3**) |
| 16 | met in this tree. The 1.0.0 side is test evidence only |
