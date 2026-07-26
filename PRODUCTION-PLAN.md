# NDV Reviews — Marquee Overhaul + Production-Grade Plan

Status: **PLAN ONLY — no code yet.** Sourced from three code audits (marquee deep-dive, free-plugin
audit, Pro-plugin audit) run 2026-07. Severity: 🔴 Critical · 🟠 High · 🟡 Medium · ⚪ Low.

---

## Part A — Marquee overhaul (free plugin)

**Files:** `templates/marquee.php`, `assets/css/marquee.css`, `assets/js/marquee.js`,
`includes/Display/Widgets.php` (`marquee()`/`marquee_items()`),
`includes/Integrations/Elementor/Widgets/MarqueeWidget.php`, `includes/Integrations/Shortcodes.php`,
plus the Gutenberg marquee block.

### A1 🟠 Fix the seamless infinite loop (the core ask)
- **Root cause:** the animation is on `.ndvr-marquee-track` and translates `calc(-100% - gap)` = the
  **whole track** (both duplicated groups), so the entire strip scrolls off then snaps back — a visible
  jump, not a loop.
- **Fix:** move the animation onto each `.ndvr-marquee-group` and translate exactly one group width
  (`-100%` of the group) — OR keep it on the track but translate `-50%` (two-group track). Standard
  Magic-UI pattern: track = N identical groups, animate `translateX(0 → -100%/N)`.
- Account for the inter-group `gap` in the translate distance (the current `- gap` term only makes
  sense for the per-group approach).
- **Fill the viewport:** duplicate groups enough times to always overflow (compute from item count, or
  bump `marquee_repeat` default to ≥3 and repeat until width ≥ 2× container). Few reviews (2–3, as in
  the current storefront) otherwise leave an empty scrolling band.

### A2 🟠 Left/Right (and Up/Down) direction control
- Replace the single `reverse` boolean with a real `direction` control: **left / right** for horizontal,
  **up / down** for vertical (map right/down → the existing `reverse` CSS internally).
- Expose it on **all surfaces**: Elementor `MarqueeWidget` (currently only horizontal/vertical),
  the `[ndvr-marquee]` shortcode (already has `reverse`; add named `direction`), and the block.

### A3 🟡 Visual polish ("make it look better")
- **Avatar bug:** `marquee.php` calls `get_avatar('', 36, '', $author)` with an **empty identifier** →
  always the mystery-person avatar. Pass the review's user id / email so real avatars show.
- Responsive card width: replace fixed `width:300px` with `clamp()`/min-max so cards size down on mobile.
- Consistent card heights, hover elevation/scale, optional quote glyph, refined shadow.
- Make the edge-fade mask width configurable (hardcoded 9%/91%); optionally a gradient overlay theme.
- Normalize speed to **pixels/second** (duration currently fixed, so more cards = faster scroll).
- Add the **double-row / bidirectional** marquee variant (two tracks opposite directions) that was
  scoped earlier — a distinct "look better" upgrade.

### A4 🟡 Marquee data/control gaps
- `source='category'` + `category` arg are accepted but **never applied** (no-op) in `marquee_items()` —
  either implement category scoping or remove the option.
- `min_rating` filters **after** the DB `limit`, so a high threshold can yield very few cards — fetch
  more then trim, or push the rating filter into the query.
- Elementor widget is missing `gap`, `pause`, `with_media`, `source`, `direction(left/right)` — bring it
  to parity with the shortcode, plus Style controls (card bg/border/radius/shadow, star color).

---

## Part B — Free plugin, production-grade

### B1 🔴 Zero-rating reviews corrupt aggregates
- Forms don't require a star rating (radios not `required`, no JS/server validation). A 0-rating review
  is displayed but **excluded** from the product average/distribution/filters — WooCommerce aggregates
  silently desync. `RatingCache::recalc_review()` writes `rating` meta only when `decimal > 0`.
- **Fix:** require ≥1 rating (client + server); on recompute-to-zero, **delete** the stale `rating` meta
  (currently left stale on downgrade).
- Files: `includes/Forms/ReviewForm.php`, `TestimonialForm.php`, `assets/js/reviews.js`,
  `includes/Reviews/RatingCache.php`.

### B2 🟠 Upload-before-validation abuse vector
- Photos are stored via `media_handle_upload()` **before** `ReviewRepository::create()` validates the
  body; failed submissions leave **orphaned attachments**. The rate limiter only counts **successful**
  submissions, so an unauthenticated visitor can loop empty-body requests and flood the media library.
- **Fix:** rate-limit **before** upload and count every attempt; upload only after body validation;
  delete attachments if `create()` fails.
- Files: `includes/Forms/ReviewForm.php:341`, `TestimonialForm.php:215`, `includes/Forms/AntiSpam.php`,
  `includes/Forms/Upload.php`.

### B3 🟡 N+1 queries on every review page
- `ReviewQuery::to_view()` runs `criteria_scores()` + `media()` per review → ~20 extra queries per
  10-item page. **Fix:** batch-fetch criteria + media for all comment ids in the page (`WHERE
  comment_id IN (…)`), map in PHP. File: `includes/Reviews/ReviewQuery.php`.

### B4 🟡 Cleanup correctness
- **Deactivator** clears a cron hook (`ndv_reviews_daily`) that is never scheduled; the real reminder
  actions (`ndvr_send_request`, Action Scheduler group `ndv-reviews`) are **not** unscheduled.
- **uninstall.php** (opt-in data removal) misses: `ndv_reviews_unsubscribed` option, review comments +
  `_ndvr_*` meta, uploaded attachments, transients (`ndvr_rl_*`, `ndv_reviews_activated`), pending AS
  jobs. Files: `includes/Deactivator.php`, `uninstall.php`.

### B5 🟡 Accessibility
- AJAX list refresh has no `aria-live` region → silent to screen readers (`assets/js/display.js`).
- No photo **lightbox** — photos are plain `target="_blank"` links (navigates away). Build an accessible
  lightbox (keyboard-dismiss, focus trap). This also unblocks the marquee/part "Photos" UX.
- Filter/star/topic pills lack `aria-pressed`/`aria-current`; helpful vote gives no SR feedback.

### B6 ⚪ WordPress.org / disclosure
- Disclose Google reCAPTCHA usage (opt-in external service) in `readme.txt` privacy section.
- Note: no `load_plugin_textdomain` (fine for .org auto-load, but self-hosted installs won't translate).
- Windowed pagination instead of rendering every page button; drop unused Pro tables from the free
  installer (or create them lazily).

### B7 ⚪ Free-tier feature gaps (optional, competitive)
- Admin email on new pending review; "report/flag review" control; helpful-vote undo; empty-state UX.

---

## Part C — Pro plugin, production-grade + licensing

### C1 🔴 Public AI translate endpoint = unmetered spend/DoS
- `wp_ajax_nopriv_ndvr_translate` → `AiService::translate()` makes a **live paid API call with no cache,
  no rate limit**, on a public nonce, for **any** comment id. A bot can drain the merchant's AI key.
- **Fix:** cache per `comment_id+locale` in `ndvr_ai_meta` (as the docstring already claims), rate-limit,
  restrict to review comment types. Files: `includes/Multilingual/Translate.php`, `includes/AI/AiService.php`.

### C2 🟠 Settings save clobbers keys owned by other screens
- `Admin/SettingsPage.php` does a **full `update_option()` replace** from a fixed key list; other writers
  (`ExternalReviews::save_sync_interval`, and the missing `automation_steps`/`sms_template`/`wa_template`)
  live in the **same option** → saving Pro Settings **erases** them (e.g. external sync interval resets).
- **Fix:** every Pro screen must **merge** into `ndv_reviews_pro_settings`, never replace.

### C3 🟠 Duplicate review-request senders (double/triple messaging)
- Three systems fire on order completion with no mutual exclusion: free `Requests\Scheduler`, Pro
  `Automation\Engine`, Pro `Esp\Dispatcher`. The UI claims automation "replaces the free reminder" but
  nothing disables `reminder_enabled`. **Fix:** suppress the free reminder (filter) when Pro
  automation/ESP is active.

### C4 🟠 Half-built features that read unsettable settings
- **Multi-step/SMS/WhatsApp drip is non-functional:** `Automation\Engine` reads `automation_steps`
  (never saved, no UI) → always falls back to a single email step. `sms_template`/`wa_template` likewise
  unsettable. Either **build the steps UI** or stop advertising multi-step/SMS/WhatsApp drip.
- **Trust badges dead:** `Feeds\Badges` reads `google_profile_url`/`trustpilot_url` (no fields; zeroed on
  every save per C2); Trustpilot badge shows the site's own Woo aggregate mislabeled "Trustpilot".
- **Duplicate `[ndvr-google-badge]`** registered by both `Feeds\Badges` and `External\ExternalReviews`
  (ExternalReviews wins) — remove the dead one.

### C5 🟡 Version header mismatch
- `ndv-reviews-pro.php` header says `Version: 1.7.4` while `NDVR_PRO_VERSION` is `1.9.0` — WP shows the
  wrong version and update logic keys off the header. Bump the header to match.

### C6 🟡 Robustness / correctness
- Q&A vote endpoint has **no dedup/rate limit** (`vote_question()` blindly `votes+1`) — add per-user/IP
  dedup like the free helpful-vote unique key.
- Move AI **summary regeneration off the front-end request** (a stale-cache visitor eats a 30s blocking
  API call in `after_summary`) → Action Scheduler only.
- **Mask secrets** on the Pro Settings form (AI/Twilio/WhatsApp/ESP keys are echoed as input values); the
  masked "leave blank to keep" pattern already exists in `ExternalReviews` — apply everywhere.
- `AutoPoster` marks `_ndvr_posted` even when the post failed (fire-and-forget) — check response, allow
  retry. Add a nonce to `ManualReviews::ajax_search`. `ManualReviews` insert bypasses
  `ndv-reviews/review_created` (skips AI/webhooks) — document or wire it in.

### C7 🟡 i18n
- No `load_plugin_textdomain( 'ndv-reviews-pro' )` + no `/languages` loader → bundled translations never
  load (off-directory paid plugin has no auto-source). Add it.

### C8 🟡 Licensing readiness (deferred by design, but plan the path)
- Gate is a clean single choke point (`License::is_pro_active()` / `can()` + `FeatureFlags`) — good.
- To ship paid: add license-key storage + activation/deactivation UI + remote validation (EDD/Lemon
  Squeezy/WPLM) with cached-status transient + grace period; add an **update checker** (off-directory
  plugins get no .org updates); add a **tier→feature map** in `FeatureFlags` (today every key is on).
- Minor: modules are `new`-instantiated before the `can()` check; always-on admin screens bypass
  per-feature gating — decide intended behavior.

### C9 ⚪ Pro feature gaps (competitive)
- Surface the **sentiment/spam AI scores** already computed+cached (trend, spam queue, per-review
  scores) — data exists, UI doesn't. Request→review **conversion tracking** on tokened reminder links.
  Per-review CSV export with AI fields. Image AI moderation / reviewer reputation / velocity dedup.
  Clean up the orphaned `ndvr_review_manager` role on uninstall.

---

## Part D — Elementor Style tabs for all review widgets

**Problem:** none of our review Elementor widgets are customizable. The 4 free widgets (Stars, Summary,
Review Section, Marquee) expose **only a Content section — no Style tab at all**; the 11 Pro "part"
widgets have only sparse, uneven style controls (Helpful = label + align only; Photos = thumb-size
only). Users can't match the widgets to their theme without writing custom CSS.

**Approach (low-risk, no render changes):** every widget already outputs stable `.ndvr-*` classes, and
`display.css` already reads CSS custom properties (`--ndvr-gold`, `--ndvr-accent`, `--ndvr-line`…). So
add Elementor **Style-tab** sections whose controls use `selectors` → `{{WRAPPER}} .ndvr-… { prop:
{{VALUE}} }` (or set a CSS var). Elementor generates scoped CSS; the PHP render is untouched. Use
Elementor group controls (`Group_Control_Typography`, `Group_Control_Border`, `Group_Control_Box_Shadow`,
`Group_Control_Background`) and responsive controls where it matters.

**Reuse to avoid ~500 lines of duplication:** build shared helper methods (a trait or a static
`StyleControls` class) e.g. `add_card_style( $el, $selector )`, `add_stars_style( $el, $selector )`,
`add_text_style( $el, $key, $selector )`, `add_button_style(…)`, and call them from every widget. Ensure
each widget declares `get_style_depends()` so `display.css`/`elementor.css` load in the editor (parts
already do this; add to the 4 free widgets too).

### D1 — Free widgets (add Style tabs in the free plugin)
- **Stars** (`.ndvr-stars-display`): star size, filled color (`.ndvr-star-full`), empty color
  (`.ndvr-star-empty/.ndvr-star-half`), gap, alignment; count typography + color if shown.
- **Summary** (`.ndvr-summary-*`): overall-number typography/color, stars size/color, distribution &
  criteria bar colors (fill + track) / height / radius, row spacing, box background/border/radius/padding,
  alignment.
- **Review Section** (the card — biggest): card background, border, radius, box-shadow, padding, hover
  elevation; author-name typography/color; avatar size/radius/border; verified badge color/bg/typography;
  stars color/size; date typography/color; title typography/color; body typography/color/spacing; criteria
  pill label color + star size + bg; recommend yes/no colors; helpful button normal+hover text/bg/border/
  radius/padding; photo thumb size/radius/gap; grid columns/gap/row-gap; section margins.
- **Marquee** (ties to Part A3): card bg/border/radius/shadow/padding/responsive width; name/verified/
  stars/body typography & colors; edge-fade width; gap; speed. (Direction/pause stay in Content.)

### D2 — Pro part widgets (complete + normalize their Style tabs)
Bring every part to a full, consistent Style tab via the shared helpers:
- **Author**: + margin, link color/hover. **Avatar**: + border, box-shadow (has size/radius).
- **Rating**: + empty-star color, star gap (has size/filled-color). **Title/Text/Date**: + margin/spacing.
- **Verified/Recommend**: + background, padding, radius (has color/typography).
- **Photos**: + radius, gap, columns (has thumb size). **Helpful**: full button styling — normal+hover
  text/bg/border/radius/padding (currently label+align only). **Criteria**: + label typography, star size,
  row gap.

### D3 — Free vs Pro placement (decision needed)
- **Recommended:** basic Style tabs live in each widget's own plugin (free widgets styleable in free;
  part widgets in Pro). This is the Elementor-native expectation — a widget with no Style tab reads as
  broken, and shipping unstyleable free widgets hurts the free plugin's standing.
- **Alternative:** keep free widgets minimal and have Pro *inject* Style sections into them (styling
  becomes a Pro perk) via `elementor/element/{widget}/…` hooks — more surface area, feels gated.

### D4 — Global Design integration
- The plugin already has a **Design** admin screen (accent/rating icon/typography via
  `Display\Design::inline_css`). Per-widget Style controls should **layer on top of** those global
  defaults (widget selector CSS naturally wins by specificity/order). Document the precedence: Global
  Design tokens → widget Style tab overrides. Avoid `!important` so overrides remain predictable.

---

## Suggested execution order (phased)

**Phase 1 — Security & data integrity (ship first):**
C1 (AI spend), B1 (zero-rating aggregates), C2 (settings clobber), B2 (upload abuse), C3 (dup senders),
C5 (version header). These are correctness/abuse/cost bugs.

**Phase 2 — Marquee overhaul:** A1 (seamless loop) → A2 (direction) → A3 (polish) → A4 (parity/data).
Self-contained and user-visible; good standalone release.

**Phase 2.5 — Elementor Style tabs (Part D):** build the shared StyleControls helper, then D1 (free
widgets) → D2 (Pro parts) → D4 (global-design precedence). Pairs naturally with the marquee work since
both touch the Elementor widgets; decide D3 placement first.

**Phase 3 — Robustness & cleanup:** B3 (N+1), B4 (deactivate/uninstall), C4 (dead features: build or
remove), C6 (Q&A vote dedup, AI regen off front-end, mask secrets, AutoPoster), C7/B6 (i18n loaders,
disclosure).

**Phase 4 — Accessibility & UX:** B5 (aria-live, lightbox, pill states).

**Phase 5 — Licensing + competitive features:** C8 (license system + updater + tiers), C9/B7 (AI
analytics surfacing, conversion tracking, flag-review, undo vote).

## Verification per phase
- `php -l` + PHPCS (WordPress standard); `node --check` JS.
- Storefront via browser MCP: product page with ≥3 reviews → marquee loops seamlessly both directions,
  0 console errors, screenshots at desktop + mobile widths.
- Aggregates: submit a rating-less review is now rejected; approve/edit flows keep the product average in
  sync (`SHOW` the `_wc_average_rating` before/after).
- AI endpoint: hammer `ndvr_translate` unauthenticated → confirm cache hit + rate-limit (no repeat API
  calls). Settings: save Pro Settings → confirm `external_sync_interval` survives.
- Build zip + Plugin Check (free) → 0 errors before any .org resubmit.
