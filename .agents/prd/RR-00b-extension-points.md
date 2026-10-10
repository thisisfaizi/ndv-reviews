# RR-00b: Free extension points that the Pro PRDs need

Status: prd-ok (rev 4) · Plan: F (paired Pro tasks live in the Pro PRDs that consume each point) · Inherits PRD-00 + RR-00 (+ RR-09) · **Schema `Installer::V_QA_EMAIL`** (planned v7 in PRD-00 §4: `ndvr_questions.author_email`, `notified_at`; the number is fixed at merge, RR-00 F3) · **Raises `NDVR_API` to 4** (RR-00 F3b) · Built after RR-00 and RR-09, before any of Pro RR-01, 02, 06P, 10, 17, 18, 19.

Review round 1 batch C (`rosette-reviews-pro/.agents/prd/reviews/round1-C-pro.md`) found free-side changes spread across seven Pro PRDs, some specified twice and some not at all. This PRD owns every one of them. Each item is a neutral extension point: free gains an API, hook or data guarantee that any add-on could use, and contains no Pro feature logic (PRD-00 §2.6).

## 1. Problem and who it's for
Pro may not edit free files (Pro AGENTS.md §0), so every Pro feature that needs free behaviour to change needs a free change first. Today:
- Pro writes free's `review_media` table itself (Pro `Admin/ManualReviews.php:298-327`), already with `type='video'` rows (:308-317), while every free reader ignores `type` (`ReviewQuery.php:314`, `:390`) and prints the file URL inside `<img>` (`templates/review-item.php:105`).
- Pooled products show the pool's stars over an empty list, because `ReviewQuery::paginate()` queries the raw id (`ReviewQuery.php:60`) while `Summary` and `JsonLd` resolve the pool (`Summary.php:27`, `JsonLd.php:114-115`).
- The landing page has no field hook and prints only its own assets (`templates/magic-landing.php:46-141`, `Landing.php:403-436`).
- The questions table, which free creates, has no privacy export or erasure at all (`Privacy.php` covers reviews, requests, tokens and suppression only, :135-251 and :264-333).

Audience: the Pro add-on and third-party developers. Competitors expose similar surfaces through public APIs (Judge.me, Yotpo); this is infrastructure, not a storefront feature.

## 2. Scope / non-goals
In scope (each item is numbered E1–E12 and referenced by the Pro PRDs):
- **E1 Media API:** `ReviewRepository::attach_media()`; media context for `review_media_status`; a media type allowlist.
- **E2 Type-aware media reads:** `media()`, `media_bulk()`, `comment_ids_with_media()` and every free consumer (moderation edit screen, list column, dashboard KPI, CSV exporter, privacy export).
- **E3 Held-media cleanup:** files of spam and trashed reviews are removed after a retention period.
- **E4 Pool-aware reads:** `paginate()`, `comment_ids_with_media()`, `ReviewTags`, `Reviewable::has_reviewed()` / `for_order()`, `Moderation\Actions`. This PRD holds the **single definition** of `has_reviewed()` (§6.4); RR-09 and RR-15 use it.
- **E5 Pool writes:** the no-JS native post is remapped to the pool in `preprocess_comment`; `create()` and the native path stamp `_ndvr_pooled_from`; a Tools action returns orphaned pooled reviews, with their replies.
- **E6 Aggregate action:** `ndv-reviews/aggregate_saved`.
- **E7 Landing extension:** `ndv-reviews/landing_form_fields`, landing asset handle filters, and the default author for list tokens. The `list_link` source assignment itself is RR-09's (RR-09 §6.2 Landing).
- **E8 Request-size guard:** an oversized upload gets a size message instead of a generic error.
- **E9 Card text, badges and incentive disclosure:** `ndv-reviews/verified_badge_text`; `review_author_badges` documented; a new `marquee_author_badges` action; a neutral `.ndvr-pill` pill; an incentive pill driven by the comment meta `_ndvr_incentive_offered` (`offered` | `received`), printed by a free core listener on both actions (RR-00 F5 disclosure rule).
- **E10 Questions table (`V_QA_EMAIL`):** `author_email`, `notified_at`, and free privacy export and erasure for questions and answers.
- **E11 CSV cell helpers:** public `Exporter::csv_cell()` and `Csv::unguard_cell()`.
- **E12 Compatibility contract:** `NDVR_API` = 4, the level Pro checks (RR-00 F3b), and the list of API it guarantees.

**Decision: imported-verified pills are left to the importer (Pro RR-02).** Free renders no imported-verification pill. A flag from another app is that app's claim, and the app name is the importer's own data. Pro RR-02 prints its pill from the same two actions (`review_author_badges`, `marquee_author_badges`) with the `.ndvr-pill` class, so it survives theme overrides of `review-item.php` like the incentive pill does. Removing Pro then removes a positive claim only, which misleads nobody. The incentive pill differs: it discloses a material connection, so free must keep printing it after Pro is gone (PRD-00 §2.17).

Non-goals:
- Any Pro behaviour (groups, video upload, notifications, who is offered an incentive and when, imported-verification pills). Those are specified in the Pro PRDs.
- Video in the photo lightbox (`display.js` is unchanged; Pro RR-18 plays video inline).
- Multi-post pools (`post__in` across members). Pro RR-19 chose move-on-join; free keeps "a pool's reviews are stored on the pool post" (`ReviewRepository.php:89`, `:145`).

## 3. User experience
Free alone shows almost nothing new:
- **Moderation → Edit review** (`Moderation/Page.php:476`, `:536-547`): a "Media" row lists photos as thumbnails and videos as a text link "Video (opens in a new tab)", each with its "Remove" checkbox. The row label changes from "Photos" to "Photos and videos" only when a video exists.
- **Moderation list, Media column** (`ListTable.php:334-341`): "2 photos", "1 video", "2 photos, 1 video".
- **Reviews tab and Edit screen:** a video row never renders inside `<img>`.
- **Native no-JS review post on a pooled product:** after posting, the shopper returns to the product they reviewed.
- **Incentive label:** a review carrying `_ndvr_incentive_offered` shows a pill after "Verified buyer" on review cards and marquee cards:
  - `offered`: "Offered an incentive for reviewing";
  - `received`: "Received an incentive for reviewing".

  An add-on may supply more specific wording (Pro: "Offered a coupon for reviewing", "Received a coupon for reviewing").
- **Oversized upload** (product form and email landing page): "Your files are too large to send. Try a smaller file or fewer photos."
- **Tools → "Return shared reviews"** card, shown only when orphaned pooled reviews exist: "{n} reviews are stored on another product because the products shared reviews. That sharing is no longer active. Move them back to the product they were written for." A second line warns: "Only do this if these products should stop sharing reviews. If sharing is turned back on later, the reviews move to the shared product again. Replies move with their review." Button: "Move {n} reviews back". Result notice: "Moved {n} reviews back to their products."
- **Privacy export** gains the groups "Questions you asked" and "Answers you wrote".

## 4. Reuse map
`Reviews\ReviewRepository`, `Reviews\ReviewQuery`, `Reviews\Pool`, `Reviews\RatingCache`, `Reviews\AggregateStore`, `Reviews\ReviewTags`, `Reviews\Sources` (RR-00 F1), `Collection\Reviewable`, `Collection\Landing`, `Forms\ReviewForm`, `Moderation\Actions`, `Moderation\Page`, `Moderation\ListTable`, `Admin\DashboardPage`, `Admin\ToolsPage`, `Importers\Exporter`, `Importers\Csv`, `Privacy\Privacy`, `Installer` (RR-00 F3 `steps()`/`is_current()`), `Uninstall::registry()` (RR-00 F2), `Support\Caps`.

## 5. Blast radius
- **Free:** every caller of `paginate()` gets pooled results: `Renderer.php:262`, `:413`; `Widgets.php:192`, `:378`, `:390`; `JsonLd.php:146` (already passes a pool id; resolving again is a no-op by the idempotence rule in §6.4). Every reader of `review_media`: `ReviewQuery.php:314`, `:390`, `:471-474`; `Moderation/Page.php:376-393`, `:476`; `ListTable.php:337`; `DashboardPage.php:244-246`; `Exporter.php:73`, `:94`; `Privacy.php:184`, `:293-300`; `uninstall.php:55-68`.
- **Pro (consumers, verified):** `Elementor/GridRenderer.php:113`, `Widgets/Catalog.php:110`, `:523`, `Developer/RestApi.php:145` call `paginate()` with raw ids and become pool-aware with no Pro change. `Moderation/Plus.php:41` registers `review_media_status` with one accepted argument; the third argument is ignored until Pro RR-02 widens it. `Admin/ManualReviews.php:298-327` writes `review_media` directly; Pro RR-18 moves it onto `attach_media()`. `Reputation/TopReviewer.php:36` and `Reputation/Highlight.php:43` listen on `review_author_badges` at priority 10 and echo their own markup (TopReviewer.php:62, Highlight.php:151). They are unaffected: free's core listener runs at priority 5, so the incentive pill sits right after "Verified buyer", and the marquee fires its own `marquee_author_badges` action, so Highlight's inline-styled block never lands in compact marquee cards. Pro RR-01 sets `_ndvr_incentive_offered` (today `CouponReward` sets only `_ndvr_rewarded`, `CouponReward.php:117`); free renders the pill, so the disclosure survives if Pro is removed. Pro RR-02 prints its imported-verified pill from the same two actions.
- **Free consumers of `has_reviewed()`:** `Reviewable::for_order()` (Reviewable.php:37), RR-09 eligibility step 7 (list rows), RR-15 `for_account()`. Pro: Engine (Engine.php:150-151) and Dispatcher (`Esp/Dispatcher.php:116`) reach it through `for_order()`.
- **WooCommerce:** `WC_Comments::clear_transients()` recomputes a product's `_wc_*` meta from that product's own comments and saves the product (`woocommerce/includes/class-wc-comments.php:287-299`, hooked on `wp_update_comment_count` at :45), and `AggregateStore::set()` calls it (`AggregateStore.php:83-85`). E6 fires after that call, so a listener's writes win.

## 6. Contract delta

### 6.1 E1 Media API
`ReviewRepository::attach_media( int $comment_id, array $ids, string $type = 'image', array $context = array() ): int` returns the number of rows inserted.
- **Validates:** the comment exists and is a review (`comment_type` `review`, or `comment` on a reviewable post); `$type` is in `ndv-reviews/media_types` (default `['image','video']`); each id is an `attachment` post whose MIME type starts with `image/` for `image` and `video/` for `video`; ids already attached to this comment are skipped.
- **Position:** one `SELECT COALESCE(MAX(position), -1) + 1` for the comment, then increments. This fixes the restart at 0 (`ReviewRepository.php:297`).
- **Status:** `apply_filters( 'ndv-reviews/review_media_status', 'approved', $attachment_id, $context )` with `$context` merged over the defaults `['comment_id' => $comment_id, 'type' => $type, 'source' => (string) get_comment_meta( $comment_id, '_ndvr_source', true ), 'origin' => 'api']`. Unknown statuses become `approved` (as today, :321).
- **Never fires** `ndv-reviews/review_created` and never sends mail. Imports stay silent (PRD-00 §2.15).
- `create()` keeps its private `save_media()` call site but routes it through `attach_media( $comment_id, $media, 'image', ['origin' => 'create', 'source' => $source] )`, so the filter receives the context for every write. Rows and positions for a new review are byte-identical to today.
- **Filter** `ndv-reviews/media_types` (string[]).

### 6.2 E2 Type-aware reads
- `ReviewQuery::media( int $comment_id, string $type = 'image' )` and `media_bulk( array $ids, string $type = 'image' )`: `AND type = %s` unless `$type` is `'any'`. Each row gains `'type'`. For non-image rows `thumb` is `''` and `url` is the attachment URL. The `<img>` fallback to the file URL (`ReviewQuery.php:324`, `:405`) applies only to `image` rows.
- `comment_ids_with_media( $product_id, array $post_ids = array() )` restricts to the types from the filter `ndv-reviews/with_media_types` (default `['image']`, so the "With photos" toggle at `Renderer.php:325` keeps its meaning).
- **Consumers:**
  - `Moderation/Page.php:476` reads `media( $id, 'any' )` and renders video rows as a link; `remove_media()` (:374-393) is type-agnostic already.
  - `ListTable::column_media()` (:334-341) counts per type, and names the table through `Db::table()`, replacing the hand-built name at :337 (PRD-00 §2.3).
  - `DashboardPage.php:246` counts `type = 'image'` for the photos KPI.
  - `Exporter` (header :46, row :94, join :137) keeps `photos` image-only and adds a trailing `videos` column from `media_bulk( $ids, 'video' )`. `Csv` ignores unknown columns, so old and new exports both re-import.
  - `Privacy::export()` (:184) selects `url, type` and labels rows "Photo" or "Video".
  - `Privacy::erase()` (:293-300) and `uninstall.php` (:55-68) already delete every type; unchanged, now covered by a test.

### 6.3 E3 Held-media cleanup
`Moderation\Actions::register()` (Actions.php:43-48) gains a second `transition_comment_status` callback:
- When a review moves to `spam` or `trash`, it schedules Action Scheduler `ndvr_media_cleanup` (`comment_id`) in group `ndv-reviews` at `time() + DAY_IN_SECONDS * apply_filters( 'ndv-reviews/held_media_days', 7 )`, unless `as_has_scheduled_action()` already finds it.
- When the job runs and the review is still `spam` or `trash`, it deletes the review's `review_media` rows and calls `Actions::delete_photo_if_unused()` (:110-129), which keeps admin-picked library items (`_ndvr_admin_created`). If the review was restored, the job does nothing.
- Why: core purges trashed comments after `EMPTY_TRASH_DAYS` (`wp_scheduled_delete()`, `wp-includes/functions.php:6974`), which reaches `on_delete` (Actions.php:81-97), but core never purges spam, so spam uploads stayed forever.
- `ndvr_media_cleanup` is added to `Uninstall::registry()` (RR-00 F2) and is unscheduled on deactivation.
- **Re-sweep after deactivation (round 3, A8):** deactivation unschedules `ndvr_media_cleanup`, and Action Scheduler fails actions that fall due with no callback (ActionScheduler_Action.php:67-80). So the hourly `ndvr_requests_recover` job (RR-09) also re-creates missing cleanups:
  - It selects up to 200 distinct `comment_id`s from `Db::table( 'review_media' )`, joined to `{$wpdb->comments}`, where `comment_approved IN ('spam','trash')`.
  - For each one, if `as_has_scheduled_action( 'ndvr_media_cleanup', [ 'comment_id' => $id ], 'ndv-reviews' )` is false, it schedules the cleanup at `time() + DAY_IN_SECONDS * held_media_days`.
  - The job re-checks the status when it runs, as above. So after any reactivation, every held review is cleaned up within one retention period plus one hour.
  - AC (§12): mark a review with media as spam, then deactivate and reactivate (its job is gone), then run `ndvr_requests_recover`. One `ndvr_media_cleanup` is pending for it. Running that with `held_media_days` = 0 deletes its media rows.

### 6.4 E4 Pool-aware reads
Invariant, documented in `Pool::resolve_id()`'s docblock: `resolve_id( resolve_id( $x ) ) === resolve_id( $x )`. A pool post is never itself mapped to another post. Callers may resolve an id that is already resolved.
- **`paginate()`:** (as built, code review M3: it reads the product **and** its pool post, so reviews still stored on a member product never disappear) after `is_viewable( $product_id )` passes on the requested product (ReviewQuery.php:72-75), `$pool_id = Pool::resolve_id( $product_id )` drives `post_id` (:60), `ReviewTags::comment_ids_for_tag()` (:96) and `comment_ids_with_media()` (:138). `$args['product_id']` stays the requested id and `$args['pool_id']` is added, so `review_query_args` (:176) and `review_items` (:219) listeners see both.
- **`ReviewTags::for_post()`** (ReviewTags.php:76) and **`comment_ids_for_tag()`** (:120) resolve a non-zero post id first.
- **`Reviewable::has_reviewed( string $email, int $product_id, int $user_id = 0 ): bool`** (Reviewable.php:81-97) is the **one definition** every caller uses (RR-09 step 7, RR-15, Pro through `for_order()`):
  - posts: `post__in = array_unique( [ $product_id, Pool::resolve_id( $product_id ) ] )`;
  - match: the email **or** the user id. `get_comments()` ANDs `author_email` with `user_id`, so this is two count queries (`type__in ['review','comment']`, `status 'all'`, `count true`, `number 1`, as today at :86-95): one with `author_email` when `is_email( $email )`, and one with `user_id` when `$user_id > 0`, the second skipped when the first found a review;
  - the `is_email()` early return (:82-84) no longer returns false when a user id is given; it only skips the email count;
  - false when neither an email nor a user id is usable.
- **`Reviewable::for_order()`** (:24-43) passes `$order->get_customer_id()` as `$user_id`, keys its result by pool id and keeps the first ordered product id for each pool. Two members of one pool in an order produce one landing form.
- **`Moderation\Actions::on_status_change()`** (Actions.php:68) recalculates `$ratings->recalc_product( Pool::resolve_id( (int) $comment->comment_post_ID ) )`, as PRD-00 §2.4 requires.

### 6.5 E5 Pool writes
- **Comment meta `_ndvr_pooled_from`** (int, free-documented): the top-level product a review was written for, stored only when the review is stored on a different pool post. "Top-level" means the parent for a `product_variation`, otherwise the id itself. Variation pooling therefore never stamps it, because the origin equals the pool.
- **`create()`** (ReviewRepository.php:89) stamps it after insert when the origin differs from `$pool_id`.
- **Native post:** a new `ReviewForm` callback on `preprocess_comment`, priority 20, after `block_unrated_native_post` (ReviewForm.php:92, :108-132). For a top-level comment on a `product` outside the admin, it sets `comment_post_ID = Pool::resolve_id( $post_id )` when that differs and keeps the origin in a request-scoped property. On `comment_post` at priority 20, after WooCommerce's `add_comment_rating()` (priority 1, `class-wc-comments.php:41`, :239-261), it stamps `_ndvr_pooled_from` and calls `$ratings->recalc_product( $pool_id )`. That recount is required: WooCommerce adds the `rating` meta only after the insert, so the `wp_update_comment_count( $pool_id )` during insert counted without it, and WooCommerce then recounts the origin (`$_POST['comment_post_ID']`), not the pool. The recount also fires `aggregate_saved`, so a pool owner can re-mirror the origin. On `comment_post_redirect` it returns `get_permalink( $origin ) . '#comment-' . $id`. `wp-comments-post.php` checks `comments_open()` on the origin before the filter runs, so the origin's settings still govern.
- **`Pool::orphans( int $limit = 200, int $origin = 0 ): int[]`:** comment ids carrying `_ndvr_pooled_from = X` where `comment_post_ID <> X`, X is an existing reviewable post (`PostTypes::is_reviewable( X )`), and `Pool::resolve_id( X ) === X`, so no active pool maps X any more. `$origin` restricts the result to one X. Reviews whose origin product was deleted stay where they are.
- **`Pool::restore( array $ids ): int`:** for each comment, a `$wpdb->update()` of `comment_post_ID` back to X, `clean_comment_cache()`, deletion of `_ndvr_pooled_from`, then `wp_update_comment_count()` and `$ratings->recalc_product()` once per affected post. Returns the number of top-level comments moved.
  - **Descendants move with their parent.** A reply written on the pool post after the move carries no `_ndvr_pooled_from` (only reviews are stamped), so `restore()` also collects descendants: `get_comments( ['parent__in' => $batch, 'post_id' => $pool_post, 'status' => 'all', 'type' => '', 'fields' => 'ids'] )`, repeated level by level until empty (at most 10 levels, core's `thread_comments_depth` maximum), and moves them to X in the same pass with their meta untouched.
  - Pro RR-19 uses the same methods for leave and ungroup.
- **Tools card "Return shared reviews"** (`ToolsPage`, §3) calls `orphans()` / `restore()` in batches of 200 per request.

### 6.6 E6 Aggregate action
`do_action( 'ndv-reviews/aggregate_saved', int $post_id, array $data )` is the last statement of `AggregateStore::set()` (AggregateStore.php:72-91), after `WC_Comments::clear_transients()` (:83-85), with the normalized `{average, count, counts}`. The docblock says listeners must not call `AggregateStore::set()` for the same `$post_id` (recursion), and that a product mirror should be written with the product setters and `save()`, so `wc_product_meta_lookup` (used for "Sort by average rating", `class-wc-query.php:879-882`) stays in step.

### 6.7 E7 Landing extension
- **Action** `ndv-reviews/landing_form_fields( int $product_id, array $criteria )` in `templates/magic-landing.php`, after the photo field (:114-120) and before consent (:122). Each product has its own `<form>` (:63), so field names need no product prefix, but element ids must include the product id. `collect.js` posts `new FormData( form )` (collect.js:79), so file inputs are sent. The template docblock lists the action among the hooks overrides must keep (RR-00 F5).
- **Filters** `ndv-reviews/landing_style_handles` (string[], default `['ndvr-collect','ndvr-reviews']`) and `ndv-reviews/landing_script_handles` (string[], default `['ndvr-collect']`), applied in `Landing::output_page()` before `wp_print_styles()` (:422) and `wp_print_scripts()` (:431). That page never calls `wp_head()`/`wp_footer()` (:403-436), so this is the only way an add-on's registered handle reaches it. Only registered handles print.
- **List tokens, default author:** `default_author()` (Landing.php:334-371) uses the request row's `meta.first_name` (RR-09 `find_by_token()`) for list tokens, falling back to "Customer".
- **Not in this PRD:** the `list_link` source assignment in `handle_submit()` (today hard-coded `magic_link`, Landing.php:295), the list-token email and the skipped verified override (:311-313) are RR-09 §6.2 Landing, tested by RR-09 AC7. `list_link` is in the RR-00 F1 default list already.

### 6.8 E8 Request-size guard
- **Today:** when a body exceeds `post_max_size`, PHP drops `$_POST` and `$_FILES`. The `action` field travels in that body (`collect.js:79-85`, `reviews.js:138-149`), so `admin-ajax.php` stops with `wp_die( '0', 400 )` before any handler runs (`wp-admin/admin-ajax.php:31-32`), and the scripts show their generic "Something went wrong."
- **Change:**
  - `collect.js` and `reviews.js` also put `action` in the request URL (`ajaxUrl + ( ajaxUrl.indexOf( '?' ) < 0 ? '?' : '&' ) + 'action=' + encodeURIComponent( action )`), rebuilt to `.min`, so `admin-ajax.php` still dispatches.
  - `Forms\Upload::request_too_large(): bool` is true when `$_POST` and `$_FILES` are both empty and `CONTENT_LENGTH` exceeds `wp_convert_hr_to_bytes( ini_get( 'post_max_size' ) )`.
  - `ReviewForm::handle_submit()` and `Landing::handle_submit()` call it before their nonce checks (ReviewForm.php:351, Landing.php:213), and return HTTP 413 JSON with the §3 message, which the scripts display.

### 6.9 E9 Card text and badges
- **Filter** `ndv-reviews/verified_badge_text( string $text, array $review )`, default the translated "Verified buyer". Applied in `templates/review-item.php:38` and in both strings of `templates/marquee.php:45`.
- **Documented (existing):** action `ndv-reviews/review_author_badges( array $review )` at `templates/review-item.php:47`, inside `.ndvr-review-author`, after the verified badge. It is missing from CONTRACTS today.
- **New action** `ndv-reviews/marquee_author_badges( array $review )` in `templates/marquee.php`, inside `.ndvr-marquee-name`, right after the verified mark (:44-46). It is separate from `review_author_badges` so listeners written for full cards (Pro Highlight prints an inline-styled block, Highlight.php:151) don't land in compact marquee cards. The marquee items are full view-models from `paginate()` (`Widgets::marquee_items()`, Widgets.php:358-381), so every key below is present.
- **Neutral pill class** `.ndvr-pill`: a small inline pill using `--ndvr-*` tokens, not a warning or status colour (PRD-00 §2.7). Any listener may use it for its own pill (Pro RR-02 does).
- **Incentive disclosure (new, neutral):**
  - Comment meta `_ndvr_incentive_offered` is free-documented: "the reviewer was offered (`offered`) or given (`received`) something for reviewing". Any add-on, or the store's own code, that rewards reviewers sets it, and nothing in free sets or clears it. Any other truthy value (for example `1` set by store code) reads as `offered`.
  - `ReviewQuery::to_view()` (ReviewQuery.php:251-264) adds `'incentive'` (`''`, `'offered'` or `'received'`) and `'incentivized'` (bool, true when `incentive` is not empty), read from the meta cache `WP_Comment_Query` already primes.
  - **Rendering is a listener, not a template block** (RR-00 F5 disclosure rule). New class `Display\ReviewBadges`, registered by `Plugin` **unconditionally** (no `is_admin()` guard: the "load more", filter and sort lists are rendered through admin-ajax, `Renderer::ajax_list()` on `ndvr_list_reviews`, Renderer.php:84-85 and :397, where `is_admin()` is true, and so is the Elementor editor preview), adds `incentive_pill( array $review )` to both `review_author_badges` and `marquee_author_badges` at **priority 5**, before Pro's listeners at 10 (TopReviewer.php:36, Highlight.php:43). When `$review['incentive']` is not empty it prints `<span class="ndvr-pill ndvr-incentive-badge">{label}</span>` (`esc_html`).
  - **Free strings:** `offered` → "Offered an incentive for reviewing"; `received` → "Received an incentive for reviewing".
  - The label passes through the filter `ndv-reviews/incentive_label( string $label, array $review )`; `$review['incentive']` tells the listener which value it is. An empty or non-string result falls back to the free string for that value.
  - There is deliberately no `show_*` filter, so the pill can't be switched off (PRD-00 §2.17). The `review-item.php` and `marquee.php` docblocks list both actions among the hooks overrides must keep.
  - CSS `.ndvr-pill` and `.ndvr-incentive-badge` go in `display.css` and `marquee.css` (+ `.min`).

### 6.10 E10 Questions table, `Installer::V_QA_EMAIL`
- `Installer::schema()` `questions` (Installer.php:132-143) gains `author_email varchar(191) DEFAULT NULL`, `notified_at datetime DEFAULT NULL` and `KEY email_idx (author_email)`. No migration step. `Installer::V_QA_EMAIL` takes `max(shipped) + 1` at merge (planned 7, PRD-00 §4), and `NDVR_DB_VERSION` becomes that number.
- **Privacy export** (new groups in `Privacy::export()`, last page):
  - "Questions you asked": questions where `author_email = %s`, or `user_id` is the account that owns the email. Fields: product, question, date, status, and "Email me when answered: Yes" when `author_email` is set.
  - "Answers you wrote": answers where `user_id` is that account.
- **Privacy erasure:** those questions get `author_name = 'Anonymous'`, `user_id = NULL`, `author_email = NULL`; those answers get `author_name = 'Anonymous'`, `user_id = NULL`. The question and answer text stays, the same policy as reviews (Privacy.php:275-288).
- The `author_email` parts run only when `Installer::is_current( Installer::V_QA_EMAIL )`. The name and user parts work on v2.
- **Uninstall:** the table is already dropped (uninstall.php:27; RR-00 F2 moves the list to `Installer::table_names()`).
- `readme.txt` privacy section: "Q&A: the name a shopper gives, their account id, and, if they ask to be emailed when their question is answered, their email address. Exported and erased with the WordPress privacy tools."

### 6.11 E11 CSV cell helpers
- `Importers\Exporter::csv_cell( $value )` becomes `public static` (today a private instance method, Exporter.php:111). Internal calls change to `self::csv_cell()`.
- New `Importers\Csv::unguard_cell( string $value ): string` holds the inline undo at Csv.php:105-108, which then calls it.

### 6.12 E12 Compatibility contract (`NDVR_API` 4)
- **This PRD sets `NDVR_API` to 4** (`define( 'NDVR_API', 4 )` beside `NDVR_VERSION`, rosette-reviews.php:34; RR-00 F3b defines the constant). Level 4 is cumulative. RR-00 and RR-09 merge before this PRD, so a free that reports 4 has all of their API too. Pro features in this programme gate on 4.
- **What level 4 guarantees** (the list CONTRACTS records):
  - RR-00: `Reviews\Sources::interactive()` / `is_interactive()` / `is_customer_editable()`; `Installer::is_current()` and the `Installer::V_*` constants; the F4 moderation filters and action; the F5 template actions and filters; `Mailer::send_notice()`; `rate_limit()`, `honeypot_ok()` and `ip_hash()` on the container service `antispam` (`Plugin.php:130-132`), called as `Plugin::instance()->container()->get( 'antispam' )->rate_limit( $bucket, $max )`.
  - RR-09: `queue_for_order()` / `queue_for_email()` on the `scheduler` service and `check_eligibility()` on the `mailer` service (instance methods, reached through the container); `Scheduler::SKIP_CODES`; the actions `request_sent` and `request_converted`; `TokenRepository::create_list_token()`.
  - RR-00b: E1 to E11 above, including `marquee_author_badges` and the `offered`/`received` incentive values.
- **How add-ons check:** `defined( 'NDVR_API' ) && NDVR_API >= 4`. Free 1.0.0 and older define no `NDVR_API`, which counts as below every level. Add-ons don't use `method_exists()` or `version_compare( NDVR_VERSION, … )` for this. `NDVR_VERSION` stays a display value only, unchanged by this PRD (PRD-00 §2.14).
- **Schema-backed features** still check `Installer::is_current( Installer::V_QA_EMAIL )` (or the matching constant) at run time, because the API level says the code exists, not that the upgrade has run.
- Below the level, an add-on keeps the dependent feature off and shows the RR-00 F3b notice: "Update Rosette Reviews to use {feature}."

### 6.13 CONTRACTS.md additions (both repos, Pro mirror)
Constant `NDVR_API` (4) as the compatibility contract, with the §6.12 list. Actions `aggregate_saved`, `landing_form_fields`, `marquee_author_badges`, `review_author_badges` (documented). Filters `verified_badge_text`, `incentive_label`, `media_types`, `with_media_types`, `held_media_days`, `landing_style_handles`, `landing_script_handles`; `review_media_status` third argument `$context`. Methods `attach_media`, `media()`/`media_bulk()` `$type`, `has_reviewed()` third argument `$user_id`, `Pool::orphans()`/`restore()` (restore moves descendants), `Upload::request_too_large()`, `Exporter::csv_cell()`, `Csv::unguard_cell()`. Comment meta `_ndvr_pooled_from`, `_ndvr_incentive_offered` (`offered` | `received`). View-model keys `incentive`, `incentivized`. CSS classes `.ndvr-pill`, `.ndvr-incentive-badge`. Action Scheduler `ndvr_media_cleanup`. Columns `questions.author_email`, `questions.notified_at` (`V_QA_EMAIL`).

## 7. Storage and upgrade
- `V_QA_EMAIL` adds two nullable columns and an index (dbDelta, cumulative; PRD-00 §4). An old site sees no change until the upgrade runs on `init` or `admin_init` (RR-00 F3). Before then `is_current( Installer::V_QA_EMAIL )` is false and the email parts of E10 stay off.
- `NDVR_API` is a code constant, not stored data, so it needs no upgrade step.
- `_ndvr_pooled_from` is written only when a `review_pool_id` listener maps a product elsewhere. Free alone never writes it.
- `_ndvr_incentive_offered` is read by free and written only by add-ons.
- No option shape changes and no new tables.

## 8. Security
- **`attach_media()`** is a PHP API with no request entry point. It validates the attachment post type, MIME family and comment type, and casts every id with `absint`. Callers own capability checks.
- **Tools "Move reviews back":** admin POST, `check_admin_referer( ToolsPage::NONCE )`, then `current_user_can( Caps::manage( 'tools' ) )` (ToolsPage.php:114-119 pattern), then work. SQL through `$wpdb->prepare()`; ids `absint`.
- **Native-post remap:** never runs in the admin or on replies (`comment_parent`); the post id only ever changes to `Pool::resolve_id()` of a product.
- **Landing hooks:** listeners escape their own output. The handle filters print only handles already registered (`wp_script_is( $h, 'registered' )`).
- **Request-size guard:** returns before any work; it reads only `$_SERVER['CONTENT_LENGTH']` (cast with `absint`).
- **Privacy export/erase:** core privacy screens (core capability and nonce); SQL prepared; table names from `Db::table()`.

## 9. Privacy
- `questions.author_email` is new personal data: exporter, eraser, uninstall (table drop) and readme notes per §6.10 (PRD-00 §2.9). Answer authors' names and user ids were already personal data with no coverage; E10 fixes that too.
- Video media rows are covered by the existing export (now labelled) and erasure (Privacy.php:293-300).
- `_ndvr_pooled_from` is a product id, not personal data.

## 10. Performance and assets
- `paginate()`: one extra `apply_filters` call. `has_reviewed()`: one count query with `post__in`, plus a second only for a signed-in customer whose email matched nothing.
- `media()`/`media_bulk()`: an extra indexed predicate on `comment_idx`.
- `attach_media()`: one `MAX(position)` query per call.
- `aggregate_saved`: a no-op without listeners.
- `ndvr_media_cleanup`: at most one action per spam or trash transition.
- `Pool::restore()`: one `parent__in` query per reply level per batch.
- The incentive pill reads the primed meta cache: no query per card. CSS: a few rules for `.ndvr-pill` in the existing `display.css` and `marquee.css`. No new JS.

## 11. Compatibility
- HPOS: no order access changes. Block checkout: unaffected. PHP 7.4 / WP 6.0 / WC 8.0: no new language or API features (Action Scheduler ships with WC).
- **Theme overrides:**
  - An old `review-item.php` override keeps the hard-coded "Verified buyer" text, and **still shows the incentive pill**, because it already contains `review_author_badges` (review-item.php:47) and the pill is a listener.
  - An old `marquee.php` override has no `marquee_author_badges` and shows no pill. The marquee docblock lists the action among the hooks overrides must keep, and the readme FAQ says so.
  - An old `magic-landing.php` override has no `landing_form_fields` and shows no add-on fields; the review still submits.
- **Elementor:** unaffected. Pro's grid calls `paginate()` and gains pooling.
- **Pro absent:** no listeners, identical output except the three moderation/dashboard/export media fixes and the size message.
- **Old Pro with this free:** `Plus::media_status` ignores the extra argument. Pro ManualReviews' direct writes still work.
- **New Pro with old free:** Pro checks `NDVR_API >= 4` (E12) and keeps the dependent features off, with the one RR-00 F3b notice.

## 12. Acceptance criteria
All are run in WordPress Playground with WooCommerce through PHP (`wp eval-file` or a blueprint `runPHP` step). Pro is inactive unless stated; "a pool filter" means a test `add_filter( 'ndv-reviews/review_pool_id', fn( $p, $id ) => $id === $B ? $A : $p, 10, 2 )`.
1. `attach_media( $c, [ $img1, $img2 ], 'image', ['origin' => 'import'] )` on a review that already has one photo inserts 2 rows at positions 1 and 2, returns 2, and a test `review_media_status` listener receives `$context['origin'] === 'import'`. Re-running it inserts 0 (dedupe). Passing a PDF attachment inserts 0.
2. A review with one image row and one video row (`attach_media( $c, [ $mp4 ], 'video' )`): `media( $c )` returns 1 row; `media( $c, 'video' )` returns 1; `media( $c, 'any' )` returns 2; the product page HTML has no `<img` whose `src` ends in `.mp4`; the moderation list Media column reads "1 photo, 1 video".
3. Marking that review spam schedules exactly one `ndvr_media_cleanup`. Running it after `held_media_days` is filtered to 0 deletes both rows and both attachments. A second review marked spam and then approved before the run keeps its media.
4. With a pool filter, a review created for B is stored on A with `_ndvr_pooled_from = B`. `paginate( ['product_id' => B] )` returns it, and `ReviewTags::for_post( B )` counts its tags. `Reviewable::has_reviewed( $email, B )` is true.
   - `has_reviewed()`, one definition: a review on A by user 7 whose comment email is `old@example.net` makes `has_reviewed( 'new@example.net', B, 7 )` true (user id match), `has_reviewed( 'old@example.net', B )` true (email match), `has_reviewed( '', B, 7 )` true (no email, user id still checked) and `has_reviewed( 'new@example.net', B, 8 )` false.
5. An order containing A and B under the pool filter yields `Reviewable::for_order()` with one id (A's or B's, the first ordered). For an order by user 7 (billing email `new@example.net`), the AC4 review makes `for_order()` empty.
6. A simulated native post stores the comment on A, stamps `_ndvr_pooled_from = B`, and A's `_wc_average_rating` includes the new rating. The test sets `$_POST['comment_post_ID'] = B` and `$_POST['rating'] = 4` (both free's guard and WooCommerce read them), calls `wp_handle_comment_submission()` with the same values, and applies `comment_post_redirect` explicitly (it fires only in `wp-comments-post.php`), which returns B's permalink.
7. A test listener on `aggregate_saved` records `( A, data )` once per `recalc_product( A )`, and its value is read after `clear_transients()` ran (it sees the final `_wc_average_rating`).
8. Removing the pool filter: `Pool::orphans()` returns the comment from AC4; the Tools POST with a valid nonce moves it to B, deletes the meta and recalculates A and B. A reply to it stored on A (no `_ndvr_pooled_from`) and a reply to that reply both move to B in the same POST. A POST without the nonce changes nothing.
9. The landing page for an order token renders a test `landing_form_fields` field once per product form. A test handle added through `landing_script_handles` prints a `<script>` tag. An unregistered handle prints nothing.
10. A list token (RR-09) submission with no typed name stores the request row's `meta.first_name` ("Ana") as the author; with no first name in the row, "Customer". (The `list_link` source and verification are RR-09 AC7.)
11. A request with `action` only in the query string (`$_GET` and `$_REQUEST`), `$_POST` and `$_FILES` empty, and `CONTENT_LENGTH` above `post_max_size` is dispatched the way `admin-ajax.php` does it (`do_action( 'wp_ajax_nopriv_' . $_REQUEST['action'] )`) and returns 413 with the size message, for both the landing and the product form. The built `collect.min.js` and `reviews.min.js` contain `action=` in the request URL.
12. A `verified_badge_text` filter returning "Bought here" changes the text on the reviews tab and in `[ndvr-marquee]`.
   - A review with `_ndvr_incentive_offered = 'offered'` shows "Offered an incentive for reviewing", and one with `'received'` shows "Received an incentive for reviewing", on the reviews tab, in `[ndvr-reviews]` and in `[ndvr-marquee]`. Their view-models have `incentivized === true` and `incentive` `'offered'` / `'received'`. A review with the value `1` shows the `offered` text.
   - An `incentive_label` filter returning '' still shows the free text for that value.
   - The pill is printed by the listener: `has_action( 'ndv-reviews/review_author_badges', [ $badges, 'incentive_pill' ] )` returns 5, and in the reviews-tab HTML the `ndvr-incentive-badge` span comes before a test listener's output added at priority 10.
   - A theme override of `review-item.php` copied from the 1.0.0 template (no new markup, but with `review_author_badges`) still shows the pill. A `marquee.php` override without `marquee_author_badges` shows none.
   - The second page of the reviews tab, fetched through the `ndvr_list_reviews` AJAX action (`do_action( 'wp_ajax_nopriv_ndvr_list_reviews' )` with a valid nonce, under a `wp_die_ajax_handler` capture), contains the pill for an incentivized review on that page.
13. After the `V_QA_EMAIL` upgrade, `questions` has `author_email` and `notified_at` (`SHOW COLUMNS`) and `Installer::is_current( Installer::V_QA_EMAIL )` is true. A question stored with `author_email = q@example.net` and an answer by that user appear in the privacy export. The eraser nulls the email and user id and sets the name to "Anonymous".
14. `Exporter::csv_cell( '=1+1' )` returns `'=1+1` prefixed with an apostrophe, and `Csv::unguard_cell()` reverses it.
15. With no add-on active, the core-flow harness passes and the review list, summary and schema of an unpooled product are byte-identical to before the change (snapshot diff).
16. `NDVR_API` is defined and equals 4 (`var_dump( defined( 'NDVR_API' ) && 4 === NDVR_API )` prints `bool(true)`). On a second Playground site running free 1.0.0 (`git worktree add D:/.devcache/free-1.0.0 9059c7a`, the "Release 1.0.0" commit), `defined( 'NDVR_API' )` is false, which is the old-free case every Pro PRD tests against.

## 13. Test plan
- `.agents/qa/rr-00b.php`, run through `wp eval-file` in Playground (portable Node 24, `MSYS_NO_PATHCONV=1`). It seeds two products, an order, images and an mp4 fixture copied into uploads, then asserts each criterion above with `assert`-style checks that print PASS/FAIL lines.
- AC6 calls `wp_handle_comment_submission()` directly with a crafted array, so no browser is needed.
- AC11 sets `$_SERVER['CONTENT_LENGTH']`, empties the superglobals and calls `Landing::handle_submit()` under `wp_die` capture (`wp_die_ajax_handler` filter).
- AC12 renders the override cases by placing the templates in the active theme's `ndv-reviews/` folder (View.php:24-31). AC16's second site mounts the 1.0.0 worktree as the free plugin.
- Run `.agents/qa/core-flows.php`, `php -l` and PHPCS against `D:/.devcache/qa/` for touched files.

## 14. Open questions
None.
