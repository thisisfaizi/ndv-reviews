# RR-03: Review transparency notice ("How reviews work")

Status: prd-ok (rev 5: code-review fixes M1-M4, see reviews/code-RR-03.md) · Plan: F (+ paired Pro tasks) · Inherits PRD-00 + RR-00 (+ RR-00b E9 for the badge text) · No schema change, no DB version · Raises `NDVR_API` to **5** (RR-00 F3b; planned build order, final at merge like schema versions)

Review findings applied: round 1 batch A RR-03 (all), batch B X8 via RR-00 F8.3; round 2 RR-03 items 1 to 4 and RR-14 item 2; round 3 batch B item 8 (`integrity` row references RR-21 §8, stale listener wording removed) and the filter contracts for add-on sentences.

## 1. Problem and who it's for
- **EU Omnibus Directive 2019/2161 (UCPD Art. 7(6)):** a trader that shows consumer reviews must say whether and how it makes sure the reviews come from consumers who used or bought the product.
- **UK DMCC Act 2024** and **US FTC 16 CFR 465:** both bar review suppression and require disclosure of material connections (incentives).

Who it's for: EU and UK merchants first, and any merchant who wants shoppers to trust the stars. No competitor (CusRev, Judge.me, YITH, Yotpo) generates this statement from the store's real settings. We know how reviews are collected and checked, so we can write an **accurate** notice. Accuracy is the whole feature: a false sentence is worse than none, so every sentence is chosen from facts the code can prove, and each later feature that changes how reviews are handled supplies its own sentence (RR-00 F8.3).

## 2. Scope / non-goals
In scope:
- A notice built from **keyed** sentences, each shown only when the plugin can prove it.
- Shown in a native `<details>` element directly under the rating summary on every summary surface: the WooCommerce reviews tab, `[ndvr-summary]`, `[ndvr-criteria-graph]`, `[ndvr-reviews show_summary="1"]`, the summary block and the Elementor summary widget. The classic sidebar summary widget is off by default (filterable).
- `[ndvr-transparency]` and the block `ndv-reviews/transparency` for a policy page.
- Optional merchant text.
- **Off by default on every site, new or upgraded** (owner's decision, round 2). The merchant switches it on after reading the text; a notice on our screens points to it.
- Two fixes that make sentences true:
  - `Forms\TestimonialForm` honours WooCommerce's "verified owners only" setting for products (today it ignores it, TestimonialForm.php:147-152);
  - native WooCommerce review posts are always held for moderation (`pre_comment_approved` callback, §6). Today a rated native POST is left to core (ReviewForm.php:119-121), and core can approve it through `check_comment()` (WP 7.1.3 wp-includes/comment.php:1370, filter at :1407).

Non-goals:
- Legal advice. The settings screen says the merchant is responsible for the text and should read it.
- Per-product import detection (the import sentence is store-wide).
- Any change to what Pro's `after_summary` listeners render or where.

## 3. User experience
**Settings → "Reviews and trust" card** (RR-00 F6), fieldset "How reviews work":
- Checkbox "Show a 'How reviews work' note under your ratings".
- Textarea "Anything else shoppers should know (optional)". Links, bold and italic are kept; everything else is removed.
- Help text: "Built from your settings, so it changes when they do. You are responsible for it being true: read it, and add anything specific to your store."
- Help text under the checkbox: "One sentence says you don't remove reviews because they are negative. Only switch this on if that's how you moderate."
- "Shoppers see:" followed by the sentences exactly as they render for a simple product with the saved settings (server-rendered, no JS).

**Storefront:**
```html
<details class="ndvr-transparency">
  <summary>How reviews work</summary>
  <div class="ndvr-transparency-body"><p>…sentence…</p>…<div class="ndvr-transparency-extra">…</div></div>
</details>
```
Native element: keyboard accessible, no JS, closed by default. Uses `ndvr-tokens` variables only.

**`[ndvr-transparency]` / block:** the same sentences as plain paragraphs (no `<details>`), for a policy page.

**One-time admin notice** (every site where the note is off, fresh installs included; our screens only, same screen rule as `HealthCheck::on_relevant_screen()`, HealthCheck.php:63-70):
"Rosette Reviews can show shoppers a short 'How reviews work' note under your ratings. EU consumer law expects stores that show reviews to explain how they check them. Read the text it would show, then switch it on." Links: "Open Settings" · "Dismiss".

### Sentences (free)
Rendered in this array order. Every string is translatable, escaped with `esc_html` at output.

| Key | Sentence | Shown when |
|---|---|---|
| `source` | "Reviews come from customers." / with fact `external`: "Reviews come from customers and from our profiles on other sites." | always |
| `requests` | four full-sentence variants (no clause joining, so translators get whole sentences): base: 'After an order reaches the "%s" status, we email the customer to ask for a review.' · with `followup`: '…to ask for a review, and send one reminder if they haven't reviewed yet.' · with opt-in consent: 'After an order reaches the "%s" status, we email customers who agreed to this at checkout to ask for a review.' · with both: '…who agreed to this at checkout to ask for a review, and send one reminder if they haven't reviewed yet.' `%s` = `wc_get_order_status_name( reminder_status )` | fact `reminders` = `reminder_enabled` **and** `apply_filters( 'ndv-reviews/should_send_reminder', true, 0 )`, **and** fact `is_product_context` (`product_id` is 0, or its post type is `product`: other reviewable post types have no orders, so no request emails). Today's Pro returns false from `should_send_reminder` whenever its automation or an ESP sends instead (Pro Engine.php:59-73, which ignores the order id), so a Pro that hasn't shipped its own `requests` sentence makes this one disappear rather than name the wrong trigger. An updated Pro replaces the key. The opt-in variants are used only when `consent` is `optin` **and** `consent_legacy` is `skip` (RR-07): with "Send" for older orders, some customers get an email without having agreed, so the base variant applies. |
| `verified` | `%s` is the badge text exactly as cards print it: `apply_filters( 'ndv-reviews/verified_badge_text', __( 'Verified buyer', 'rosette-reviews' ), [] )` (RR-00b E9; until E9 ships, the translated default). Base: 'A "%s" label means the review is linked to a purchase in our store: it came through a review link we emailed after that order, or the reviewer's account or email address has a matching order.' · with fact `third_party_import`: 'A "%s" label on a review left in our store means it is linked to a purchase here: it came through a review link we emailed after that order, or the reviewer's account or email address has a matching order. On imported reviews, the label comes from the service they were imported from or from our order records.' | always, except when the filtered text is empty (no label is shown, so the key is removed). E9 callbacks receive an empty `$review` here and must handle it (documented in CONTRACTS.md). |
| `access` | fact `verification_required`: "Only customers who bought the product can leave a review." · open variant: "Customers who haven't bought the product can also leave a review. Their reviews don't have the label." | `verification_required`: always. The open variant only when `verification_required` is false **and** fact `reviews_open` is true **and** fact `submission_gated` is false. Otherwise the key is absent: with reviews closed nobody can leave one, and a `validate_review` listener may refuse non-buyers (Pro `PurchaseGating`, PurchaseGating.php:52; Pro `Moderation\Plus`, Plus.php:40), so "can also leave a review" might be false. An updated Pro replaces the key with its own `access` row. |
| `moderation` | fact `auto_approve` false: "We check reviews left on this store before they appear. We don't remove reviews because they are negative." · fact `auto_approve` true: "We don't remove reviews because they are negative." | always. `auto_approve` defaults to `has_filter( 'ndv-reviews/should_approve' )`: any listener may publish reviews straight away, as today's Pro `Plus::auto_approve` does (Plus.php:39, :52-73), so free falls back to the sentence that stays true. RR-21's high-risk hold is inline in `create()` (RR-21 §5) and adds no `should_approve` callback, so it doesn't affect `has_filter()`. An updated Pro replaces the key with its own sentence. |
| `imported` | 'Some reviews were imported from another review service. They keep the status they had there.' (The label rule for imported reviews is in the `verified` variant.) | fact `third_party_import` |
| `average` | product page, simple product: "The star rating is the average of all published reviews of this product." · variable product: "The star rating is the average of all published reviews of this product, across all its options." · no product (policy page, `product_id` 0): "Each product's star rating is the average of its published reviews." | always. Pro replaces it for grouped (pooled) products and if external reviews count toward the rating. |

**Code facts behind each sentence (verified):**
- `moderation`: free never auto-approves.
  - Every interactive entry path (`Reviews\Sources::interactive()`, RR-00 F1) passes `approved => 0`: Landing.php:298, ReviewForm.php:429, TestimonialForm.php:283. `should_approve` receives that value (ReviewRepository.php:131). These paths call `wp_insert_comment()` directly (ReviewRepository.php:160), so core's `comment_moderation` rules don't apply to them.
  - **Native WooCommerce form posts do go through core.** `block_unrated_native_post()` (on `preprocess_comment`, ReviewForm.php:91) stops an unrated post but returns a rated one to core (ReviewForm.php:119-121). Core then approves the post for users who can `moderate_comments` or own the product (wp-includes/comment.php:1365-1367), or when `check_comment()` passes (:1370): `comment_moderation` off (:47) and, if `comment_previously_approved` is on, an earlier approved comment by that author (:133). The result goes through `pre_comment_approved` (:1407). This PRD adds a free `pre_comment_approved` callback (§6) that returns `0` for those posts and keeps `spam`, `trash` and `WP_Error` results. With it, the sentence is true on every path.
  - The sentence says "left on this store" because imports arrive published: Csv.php:151 and Pro ProImporter (approved unless the source app had not published it). The `imported` sentence covers that.
- `verified`: magic-link reviews are forced verified because the token proves the order (Landing.php:311-313). On-site and form reviews are verified only for a logged-in account with a matching order; guests never are (VerifiedBuyer.php:48-56). WooCommerce native backfill uses the review's email or account (WooNative.php:80). CSV imports take the spreadsheet's `verified` column when present (Csv.php:161-168), otherwise order records; that is why the sentence has an import variant. Pro manual reviews match the email against orders (ManualReviews.php:164). Pro external reviews are stored with `_ndvr_verified = 0` (ExternalReviews.php:876). The card prints "Verified buyer" as a literal today (review-item.php:38); RR-00b E9 makes it filterable, and the sentence quotes the same filtered value so the two never disagree.
- `access`: `woocommerce_review_rating_verification_required` is enforced by ReviewForm (ReviewForm.php:386-388) and the tab form (Renderer.php:355-357). TestimonialForm does not check it today; this PRD adds the same check (section 6), otherwise the sentence would be false. Closed reviews are refused by ReviewForm (:383) and hide the tab form (Renderer.php:351). Any `validate_review` listener can refuse a submission after these checks (ReviewRepository.php:139-142).
- `imported`: third-party importers write `_ndvr_import_hash` (Csv.php:29 `HASH_META`, Pro ProImporter.php:443). The WooCommerce native backfill does not (WooNative.php:74-81 writes only `_ndvr_source=import` and verified meta), so native reviews never trigger the sentence. That is correct: they are real reviews left on this store.
- `average`: the rating is a plain mean of approved reviews (RatingCache.php:110). Reviews of variations attach to the parent product. Pooling is the filter `ndv-reviews/review_pool_id` (Pool.php:35), owned by Pro.

### Sentences other features add (RR-00 F8.3)
Each feature supplies its keyed sentence through `ndv-reviews/transparency_sentences`, or a fact through `ndv-reviews/transparency_facts`, in its own build. Nothing ships that makes a sentence here false.

| Feature | Key / fact | Sentence (owner may refine) | Shown when |
|---|---|---|---|
| RR-06 follow-up | fact `followup` | changes `requests` to the reminder variant | `followup_enabled` and reminders on |
| RR-07 consent | facts `consent` (`off`/`optin`/`optout`) and `consent_legacy` (`skip`/`send`) | changes `requests` to the "who agreed to this at checkout" variant for `optin` with `skip` | `consent_mode`, `consent_legacy_orders` |
| RR-14 report a review | key `reports` | the text is canonical in RR-14 §8 ("Abuse, legal and transparency"): it counts only signed-in reporters, or signed-in buyers | `reports_enabled` and `reports_threshold` > 0 (RR-14 §8) |
| RR-21 integrity signals | key `integrity` | the text is canonical in RR-21 §8 | `integrity_enabled` and `integrity_hold_high` (RR-21 §8) |
| Pro external reviews | fact `external` + key `external` | "Reviews marked with another site's logo come from our profile on that site and are shown as they appear there." Pro also replaces `average` if they count toward the star rating. | Pro External has published reviews on this store. **Precondition:** Pro renders a visible source label on those reviews. |
| Pro incentives | key `incentive` | "Some customers receive a coupon after leaving a review, whatever their rating. These reviews are labelled." | Pro CouponReward on. **Precondition:** Pro renders a label for `_ndvr_rewarded` reviews. Today nothing renders it (only CouponReward.php:93/117 read and write it), so this is a Pro task before the sentence may ship. |
| Pro auto-approve | replaces `moderation` | "Some reviews are published automatically. We remove reviews only if they are spam, offensive or unlawful, never because they are negative." | Pro `auto_approve_verified` on (after RR-00 F8.2 removes the star threshold) |
| Pro automation / ESP | replaces `requests` | Pro's own sentence using `automation_status` and the channel | Pro automation or any ESP on |
| Pro manual reviews | key `manual` | "Some reviews were sent to us another way, such as by email, and added by our team." | approved reviews with `_ndvr_source=admin` exist |
| Pro pooling | replaces `average` | "…of this product and the related products we group with it." | the product is in a Pro group |

## 4. Reuse map
- `Support\Settings` (defaults, Settings.php:31-63) and RR-00 F6 `SettingsPage::fields()` with the "Reviews and trust" card.
- `Display\Renderer::render_reviews_tab()` (Renderer.php:224), `Display\Widgets::summary()` (Widgets.php:144), `Widgets::criteria_graph()` (:161), `Widgets::reviews()` (:176, summary at :201-208), `Widgets::enqueue()` (:88) for the `ndvr-display` style.
- `Integrations\Shortcodes` (:43-51 registrations), `Integrations\Blocks` (`register_block_type`, `wrap()` at :277; JS `simpleBlock()` in assets/js/blocks.js:58-71), `Support\View` for the template.
- `Reviews\Sources::interactive()` (RR-00 F1) to word the moderation fact.
- `Requests\HealthCheck` notice and dismissal pattern (HealthCheck.php:63-123).
- `Forms\ReviewForm::register()` (ReviewForm.php:83-92), where the `pre_comment_approved` callback is added next to `block_unrated_native_post()`; `Reviews\PostTypes::is_reviewable()` (PostTypes.php:47-54).

## 5. Blast radius
- **Renderer (reviews tab):** gains one new action between the summary markup (Renderer.php:248) and the existing `after_summary` (:256). `after_summary` is unchanged: same place, same single argument.
- **Widgets::summary / criteria_graph / reviews(show_summary):** gain the new action, captured with output buffering because these methods return strings (Widgets.php:148). `Widgets::summary()` gains an optional second parameter `$surface` (default `'summary'`). Callers: Shortcodes.php:107, Blocks.php:196, Elementor SummaryWidget.php:168 (all keep the default) and classic SummaryWidget.php:39 (passes `'widget'`).
- **Pro, deliberately untouched:** Pro listens on `after_summary` with echoing callbacks (Ai.php:57/177, PublicCta.php:39/71). Firing `after_summary` from `Widgets::summary()`, as the review suggested, would put Pro's AI box, its regeneration enqueue (`maybe_enqueue_regen`, Ai.php:172) and the "share elsewhere" call to action on every summary, including sidebar widgets, on sites running an older Pro. The new action avoids that. Pro may opt in later.
- **TestimonialForm:** product reviews from `[ndvr-testimonial]`/`[ndvr-form]` now respect "verified owners only". This is a shared-layer behaviour change: on stores with that WooCommerce option on, non-buyers get the same error ReviewForm already returns.
- **Native review posts (`wp-comments-post.php`, admin-ajax handlers such as WooCommerce's order-review form, REST):** top-level comments on reviewable posts from outside the admin are always stored pending, whatever `comment_moderation` says, including front-end posts by store staff. Untouched (code review M1/M2, rev 5): admin screens (`is_admin() && ! wp_doing_ajax()`), admin-ajax by a user who can `moderate_comments` (the dashboard "Reply" box), replies that carry no star rating or come from a moderator, edits of a published review (edits never unpublish; an edit of a held review stays held), and results that are already `spam`, `trash` or `WP_Error` (Akismet, the disallowed list). This is a behaviour change for stores that had moderation off; the changelog says so. Free never auto-approves (RR-00 F8.2), so this closes the one path that did.
- **Importers\Csv:** fires one new action at the end of an import run.
- **Pro (paired tasks, listed in Pro TASKS):** supply the Pro rows of the table above; fire `ndv-reviews/third_party_import_done` at the end of a ProImporter run (until then the 12-hour cache delays the sentence); render the incentive and external labels before using those sentences.
- **Elementor:** the summary widget gets the notice through `Widgets::summary()`. No widget control changes.

## 6. Contract delta
- **New service** `Display\Transparency` (Registerable), container id `transparency`, registered in `Plugin::boot()`'s service list:
  - `facts( int $product_id ): array`
  - `sentences( int $product_id ): array<string,string>`
  - `render( int $product_id, string $surface, bool $collapsible = true ): string`
  - `has_third_party_import(): bool`
- **New action** `ndv-reviews/summary_footer` (int `$product_id`, string `$surface`): fires immediately after the summary box. Surfaces: `tab`, `summary`, `criteria` (`[ndvr-criteria-graph]`), `reviews`, `widget`. Transparency listens at priority 10.
- **Filter** `ndv-reviews/transparency_facts` (array `$facts`, int `$product_id`). Free defaults:
  - `reminders` (bool, as defined in the sentence table), `reminder_status_label` (string), `is_product_context` (bool: `product_id` 0 or post type `product`);
  - `followup` (false), `consent` ('off'), `consent_legacy` ('skip');
  - `verification_required` (bool);
  - `reviews_open` (bool): the free `enable_reviews` setting, and `comments_open( $product_id )` for a product, or `'yes' === get_option( 'woocommerce_enable_reviews' )` when `product_id` is 0;
  - `submission_gated` (`has_filter( 'ndv-reviews/validate_review' )`);
  - `verified_label` (string, the filtered badge text from the sentence table);
  - `auto_approve` (`has_filter( 'ndv-reviews/should_approve' )`), `third_party_import` (bool), `external` (false), `variable` (bool), `product_id` (int).
- **Filter** `ndv-reviews/transparency_sentences` (array<string,string> `$sentences`, array `$facts`, int `$product_id`). The array is keyed: one key per sentence, the value a whole translated sentence (plain text, escaped at output).
  - Replacing a key keeps its position. Keys are replaced, never appended twice; an empty string removes a key.
  - Add-ons and later features may add keys of their own. Free never needs to know them: the notice renders every non-empty value in the order of the filtered array, so a new key added the usual way (`$sentences['my_key'] = …`) renders after the free keys.
  - A key name is a stable slug (`[a-z0-9_]`). The free keys are listed in CONTRACTS.md so callbacks don't collide with them.
- **Filter** `ndv-reviews/transparency_surfaces` (string[], default `['tab','summary','criteria','reviews']`).
- **Action** `ndv-reviews/third_party_import_done` (string `$importer`): fired by `Importers\Csv` at the end of a run with `$importer = 'csv'`. Any other importer that writes `_ndvr_import_hash` may fire it with its own slug. Transparency listens and deletes the transient below whatever the slug.
- **Transient** `ndvr_tp_import` (12 h): cached result of one `get_comments( ['meta_key'=>'_ndvr_import_hash','status'=>'approve','type__in'=>['review','comment'],'count'=>true,'number'=>1] )`. Also deleted on `transition_comment_status` and `deleted_comment`.
- **Settings keys** (F6 registry, page `settings`, card "Reviews and trust"): `transparency_enabled` (bool, registered default **false**), `transparency_extra` (string, ''). At render, `transparency_extra` is read through `apply_filters( 'ndv-reviews/translate_setting', $value, 'transparency_extra', '' )` (RR-08; without RR-08 it returns the value unchanged).
- **No Activator change.** The first-install branch (Activator.php:29-31) is untouched, so a fresh install also starts with the note off.
- **Native moderation callback** `ReviewForm::hold_native_review( $approved, array $commentdata )` on `pre_comment_approved`, priority 99 (after spam plugins at the default 10), registered in `ReviewForm::register()`. It returns `$approved` unchanged when it is a `WP_Error`, `'spam'` or `'trash'`; on an admin screen (`is_admin() && ! wp_doing_ajax()`); in admin-ajax for a user who can `moderate_comments`; when `PostTypes::is_reviewable( comment_post_ID )` is false; for a reply (`comment_parent` > 0) without a posted `rating` or from a moderator; and for an edit (`comment_ID` set) of a published review. Otherwise it returns `0` (rev 5, code review M1/M2). `RatingCache::recalc_product()` counts top-level comments only (`comment_parent = 0`); products keep WooCommerce's own recount (`WC_Comments::clear_transients()`), so for products the hold is the protection.
- **`NDVR_API` 5** covers this PRD's Pro-facing API: the filters `transparency_sentences` and `transparency_facts`, the actions `summary_footer` and `third_party_import_done`, and the `transparency` service methods. Pro adds its filter callbacks and fires `third_party_import_done` unconditionally (both are inert on an older free), and calls `Display\Transparency` methods only when `NDVR_API >= 5`.
- **Shortcode** `[ndvr-transparency product_id="0"]`. **Block** `ndv-reviews/transparency` (attribute `product_id`, number, default 0), registered in `Blocks` and added to assets/js/blocks.js with `simpleBlock()` (rebuild `blocks.min.js`).
- **Template** `templates/transparency.php`. Variables: `$sentences` (string[]), `$extra_html` (string, already through `wp_kses`), `$collapsible` (bool), `$surface` (string).
- **CSS** `.ndvr-transparency`, `.ndvr-transparency-body`, `.ndvr-transparency-extra` in `assets/css/display.css` (+ `.min`).
- **User meta** `ndvr_transparency_notice_dismissed`. Nonce action `ndvr_transparency_notice`, GET argument `ndvr_transparency_dismiss`.
- **Behaviour change** in `TestimonialForm` submit: for a target whose post type is `product`, apply the same rule as ReviewForm.php:386-388 and return its message.
- **Uninstall registry** (RR-00 F2): user meta `ndvr_transparency_notice_dismissed`; transient `ndvr_tp_import`.
- **CONTRACTS.md:** all of the above, plus the sentence table key list so Pro knows the keys.

## 7. Storage and upgrade
- Two keys inside `ndv_reviews_settings`. No table, no column, no `NDVR_DB_VERSION` bump, no `Installer::steps()` entry.
- **Why no migration step:** an absent `transparency_enabled` means off (registered default false), and nothing seeds true. Every site, fresh or upgraded, and however it was updated (including deactivate, replace, reactivate, which runs `Installer::maybe_upgrade()` and its pending steps through RR-00 F3; there is no transparency step to run), sees the note off until the merchant switches it on. PRD-00 §4 lists no transparency step.
- **Notice rule:** shown to users with `Caps::manage()` on our screens while `transparency_enabled` is false and the user has no `ndvr_transparency_notice_dismissed`. Saving the "Reviews and trust" card also sets that user meta for the saving user.
- **Native moderation callback:** no storage; it changes the status of new native posts only. Existing reviews keep their status.
- An old site sees no storefront change.

## 8. Security
- **Settings:** saved through the F6 handler (`ndvr_settings` nonce first, then `Caps::manage()`, in the RR-00 F6 order). `transparency_enabled`: boolean from presence. `transparency_extra`: `wp_unslash`, then `wp_kses( $v, ['a'=>['href'=>[]], 'strong'=>[], 'em'=>[], 'br'=>[]] )`, max 1,000 characters.
- **Output:** each sentence `esc_html`; extra text printed after `wp_kses` with the same allowlist at render time (escape at output, not only at save); the summary label `esc_html__`.
- **Notice dismissal:** GET with `check_admin_referer( 'ndvr_transparency_notice' )`, then `current_user_can( Caps::manage() )`, then `update_user_meta`, then `wp_safe_redirect`.
- **Shortcode and block:** `product_id` through `absint`.
- **TestimonialForm:** the new check runs after the existing nonce and anti-spam checks and before any upload.
- **`hold_native_review`:** reads only the `$commentdata` core passes; it can only lower a status to pending, never raise one.
- No new AJAX, no public write, so RR-00 F7 (rate limiter) is not needed.

## 9. Privacy
No new personal data. The dismissal user meta is a UI flag, removed on opt-in uninstall through the registry. Readme privacy notes: no change.

## 10. Performance and assets
- Per render: settings reads (cached), one transient read, `wc_get_product()` (already loaded on product pages). No query on a warm cache.
- No JS. CSS is a few rules inside `display.css`, which every summary surface already loads (`Widgets::enqueue('stars')`). The shortcode and block render callbacks call `Widgets::enqueue('stars')` themselves, so a policy page with no other review output is styled.

## 11. Compatibility
- **HPOS:** not touched. **Block checkout:** not touched.
- **PHP 7.4, WP 6.0, WC 8.0:** `<details>`/`<summary>` work in every supported browser. `wc_get_order_status_name()` exists in WC 8.0.
- **Elementor:** through `Widgets::summary()`; editor preview shows the closed `<details>`.
- **Theme overrides:** `summary.php` overrides keep working because the action fires outside the template. `transparency.php` is overridable as `yourtheme/ndv-reviews/transparency.php`.
- **Pro absent:** the full free sentences. **Pro present but not updated:** three guards keep the free text true until Pro ships its rows: the `requests` sentence disappears while Pro's `should_send_reminder` callback suppresses free reminders, the `moderation` sentence drops its "we check before they appear" part while any `should_approve` listener exists, and the open `access` variant disappears while any `validate_review` listener exists. Pro's `after_summary` output stays tab-only. Any later Pro feature that would make a free sentence false must ship its row of the table before or with that feature.

## 12. Acceptance criteria
All run in Playground through PHP (`wp eval-file` / harness), no browser.
1. **Fresh install:** delete `ndv_reviews_settings`, call `Activator::activate()`. the `settings` service's `get( 'transparency_enabled' )` is false and `do_shortcode('[ndvr-summary product_id=P]')` contains no `ndvr-transparency`. As an admin, `set_current_screen( 'toplevel_page_ndv-reviews' )` plus `do_action('admin_notices')` prints the admin notice. After `transparency_enabled` is set to true, the same shortcode contains exactly one `<details class="ndvr-transparency">` whose first child is `<summary>`.
2. **Tab order:** with the note on and a stub `after_summary` listener that echoes `MARK`, the tab output (`Renderer::render_reviews_tab()` with `$product` set) has the summary markup, then the notice, then `MARK`.
3. **Upgrade:** a stored option without `transparency_enabled` renders no notice on the storefront. `set_current_screen( 'toplevel_page_ndv-reviews' )` plus `do_action('admin_notices')` prints the admin notice; `set_current_screen( 'dashboard' )` prints nothing.
4. **Dismiss:** a valid nonce request sets `ndvr_transparency_notice_dismissed`; a request without the nonce dies.
5. With `reminder_status=processing` and reminders on, `requests` contains `"Processing"` (the `wc_get_order_status_name` label). With reminders off, the `requests` key is absent. For a non-product reviewable post (a page with reviews enabled), `requests` is absent; for `product_id` 0 it is present.
6. A facts filter setting `followup=true`, `consent='optin'` and `consent_legacy='skip'` produces the both-clauses `requests` variant. With `consent_legacy='send'` it produces the base-plus-reminder variant, without "who agreed".
7. Only a WooCommerce native backfill (`WooNative` run): no `imported` key and the base `verified` variant. After a CSV import of one row: `imported` is present in the same request (the action cleared the transient), and `verified` is the import variant.
7b. A spy on `ndv-reviews/third_party_import_done` records the argument `'csv'` once per CSV run. With `ndvr_tp_import` cached, `do_action( 'ndv-reviews/third_party_import_done', 'other' )` deletes it.
8. A variable product shows the "across all its options" `average`; `[ndvr-transparency]` with no product shows the "Each product's" variant.
9. A stub `transparency_sentences` filter returning `moderation => 'X'` replaces the sentence (count of `<p>` unchanged); returning `''` removes it.
9b. A stub `transparency_sentences` callback that adds a new key `addon_test => 'Y'` (and changes nothing else) adds one `<p>`, and it is the last sentence paragraph, after `average` and before the merchant text.
10. A facts filter with `external=true` changes `source` to the "profiles on other sites" sentence.
11. `[ndvr-transparency]` on a page with no reviews: output has paragraphs and no `<details>`, and `wp_style_is( 'ndvr-display', 'enqueued' )` is true. The block render callback output matches the shortcode inside the block wrapper.
12. The classic `SummaryWidget` output contains no notice; adding `'widget'` through `ndv-reviews/transparency_surfaces` makes it appear.
13. `did_action( 'ndv-reviews/after_summary' )` does not increase when `Widgets::summary()` runs.
14. With `woocommerce_review_rating_verification_required=yes`, a TestimonialForm submit for a product by a guest returns the "Only logged in customers who have purchased this product may leave a review." error and creates no comment.
15. Saving `transparency_extra` = `<script>x</script><strong>ok</strong>` stores and renders only `<strong>ok</strong>`.
16. **Guards for an older Pro:** a stub `should_send_reminder` callback returning false removes the `requests` key; a stub `should_approve` listener switches `moderation` to "We don't remove reviews because they are negative."; a stub `validate_review` listener removes `access` while `woocommerce_review_rating_verification_required` is `no`, and leaves the "Only customers who bought" sentence when it is `yes`.
17. **Closed reviews:** with `comments_open()` false for P (comment status `closed`), `access` is absent for P.
18. **Badge text:** a stub `ndv-reviews/verified_badge_text` filter returning "Bought here" makes `verified` contain `"Bought here"` and not "Verified buyer". A stub returning `''` removes the key.
19. **Native moderation:** with `comment_moderation` = `0` and `comment_previously_approved` = `0`, `$_POST['rating'] = 5` and `wp_new_comment()` for a guest on product P (top-level, type `review`) stores `comment_approved = 0`. With a stub `pre_comment_approved` callback at priority 10 returning `'spam'`, it stores `spam`. A reply (`comment_parent` set) and a call after `set_current_screen( 'edit-comments' )` (so `is_admin()` is true) keep core's result.
20. Core flows pass.

## 13. Test plan
1. Playground with WooCommerce, the plugin, a simple and a variable product with approved reviews, and a page containing `[ndvr-transparency]`.
2. Harness (`.agents/qa/`): toggle settings through `Settings::update()`, render through `do_shortcode()`, `Blocks::render_summary()`, `Renderer::render_reviews_tab()` (with `global $product`), and `the_widget()` for the classic widget. Assert with string checks.
3. Import: run `WooNative` then `Importers\Csv` on a one-row fixture; check `Transparency::has_third_party_import()` before and after, with an action spy for the importer slug (AC7, AC7b). Add the stub sentence callbacks for AC9 and AC9b.
4. Notice: `wp_set_current_user( admin )`, `set_current_screen()`, capture `admin_notices`.
5. TestimonialForm: call its AJAX handler with a built `$_POST` and a valid nonce, `wp_set_current_user( 0 )`, catch the JSON die with `wp_die_handler`.
6. Native moderation: set the two discussion options with `update_option()`, build `$_POST['rating']` and the comment array, call `wp_new_comment()`, read `comment_approved` back (AC19).
7. Run `.agents/qa/core-flows.php`; record results in `LOG.md`.

## 14. Open questions
None.
