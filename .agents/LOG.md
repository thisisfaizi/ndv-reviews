# LOG.md — append-only iteration log

Newest entries at the bottom. One entry per loop iteration / handoff. Keep it evidence, not essays.

Format:
```
## <date> — <task id> — <who>
CHANGED: <files>
OBSERVED: <real output — flows run, Plugin Check, debug.log>
RESULT: pass | fail (+ next hypothesis)
```

---

## 2026-07 — bootstrap — @manager
CHANGED: AGENTS.md, claude.md (rebuilt into constraints doc), .agents/{TASKS,CONTRACTS,CONTEXT,LOG}.md,
.gitattributes (export-ignore the dev docs).
OBSERVED: multi-agent protocol adapted from the CPIU template to the NDV Reviews pair; public contract
surface extracted and verified by grepping both plugins; board seeded from shipped work (P1–P7 + S-01..S-05)
and the PRODUCTION-PLAN backlog.
RESULT: protocol established. NEXT (highest leverage): T-C1 (public AI endpoint) or T-B1 (rating-less
aggregate desync) — both are live-store risks.

## 2026-07 — Phase 1 (security & data) — @be/@sec
CHANGED (free): Forms/ReviewForm.php, Forms/TestimonialForm.php, Forms/AntiSpam.php, Reviews/RatingCache.php,
Requests/Scheduler.php (new `should_send_reminder` hook), assets/js/reviews.js, readme.txt, version → 0.9.9.
CHANGED (pro): Multilingual/Translate.php, Admin/SettingsPage.php, Automation/Engine.php, ndv-reviews-pro.php
(header+constant → 1.9.1).
OBSERVED: all 6 Phase-1 tasks (C1,B1,C2,B2,C3,C5) implemented; `php -l` clean on every changed PHP;
`node --check` clean on reviews.js. Static verification only — runtime (submit flow, aggregate sync,
translate cache hit, settings-merge persistence) still needs the Local site + login.
RESULT: pass (static). NEXT: Phase 2 marquee (T-M1 seamless loop → T-M2 direction → T-M3 polish).
RUNTIME-AC HANDOFF TO USER: (1) submit a rating-less review → rejected; (2) approve a review → product
average updates; (3) with an AI key, click Translate twice → 2nd is instant/cached, no 2nd API call;
(4) save Pro Settings → External sync interval survives; (5) enable Pro automation → only one review
request per order.

## 2026-07 — Phase 2 marquee (T-M1/M2/M3) — @fe — free 0.10.0
CHANGED: assets/css/marquee.css (animation moved to .ndvr-marquee-group, translate -100%-gap, responsive
card + hover + initials avatar styles), templates/marquee.php (initials avatar helper, replaces empty
get_avatar), Display/Widgets.php (direction normalizer left/right/up/down; repeat scales with item count),
Integrations/Shortcodes.php + Blocks.php (pass direction through), Elementor/Widgets/MarqueeWidget.php
(direction left/right/up/down + gap/pause/with_media), readme.txt, version → 0.10.0.
OBSERVED (external): built an http harness with the real marquee.css; two screenshots one frame apart show
the cards scrolled with the full width still filled — seamless, no blank band; colored initials avatars
render; 0 console errors. php -l clean on all PHP, node --check clean.
BLAST RADIUS: Pro reuses .ndvr-marquee-head/-name/-verified — all class names preserved (additive only);
Pro not touched, no Pro bump.
RESULT: pass. NEXT: Phase 2.5 Elementor Style tabs (T-ST), or T-M3b marquee data gaps.

## 2026-07 — page-speed pass (PS-1..4) — @be/@fe — free 0.10.1 / Pro 0.10.0
CHANGED (free): includes/Support/Assets.php (NEW loader-src min filter), includes/Plugin.php (register it),
bin/minify-assets.mjs (NEW), package.json (build:assets + esbuild), assets/**/*.min.* (built),
AGENTS.md + claude.md (conditional-loading invariant), readme.txt, version → 0.10.1.
CHANGED (pro): includes/Display/RatingStyles.php (glyph CSS now lazy via stars_html, no global enqueue),
bin/minify-assets.mjs + package.json (NEW), assets/**/*.min.* (built), CLAUDE.md, .gitattributes
(export-ignore bin/package), version → 0.10.0.
OBSERVED (browser, cleared buffer): product page serves display.min.css/js, reviews.min.*, marquee.min.*,
and Pro qanda.min.css / elementor.min.css — all 200, one free filter covers both plugins; 0 console errors.
Home page (?nocache) loads ZERO ndvr-* assets — no CSS/JS, no RatingStyles inline style. Audit had already
confirmed free = 100% conditional; RatingStyles was the only Pro violation, now fixed.
RESULT: pass. Both plugins: nothing loads on pages without a reviews feature; served assets are minified.
NEXT: Phase 2.5 Elementor Style tabs (T-ST) or Phase 3 robustness.

## 2026-07 — Phase 2.5 Elementor Style tabs (T-ST) — @fe — free 0.11.0 / Pro 0.11.0
CHANGED (free): NEW includes/Integrations/Elementor/Widgets/WidgetStyleTrait.php (add_color_control
supports one selector or an array of selectors sharing a control; add_typography_control wraps
Group_Control_Typography); Widgets/{Stars,SummaryWidget,ReviewsWidget,MarqueeWidget}.php each gain a
Style-tab section using the trait + Elementor's own Group_Control_Background/Border/Box_Shadow for
card-like elements. readme.txt, version → 0.11.0.
CHANGED (pro): includes/Elementor/Widgets/Parts/PartBase.php gains add_color_control/
add_typography_control (same array-selector support as the free trait); PhotosPart.php + HelpfulPart.php
get their first Style tab (Helpful uses start_controls_tabs for Normal/Hover states); RecommendPart.php
gains yes/no color controls; CriteriaPart.php gains label typography + star size/color. Version → 0.11.0.
OBSERVED: built a stub Elementor API (Widget_Base/Controls_Manager/Group_Control_* stand-ins) and executed
register_controls() via reflection on all 4 free widgets + all 11 Pro parts (including the 7 NOT touched
this task, to confirm the PartBase change didn't break them) — 0 runtime errors, control-call counts
logged per widget. php -l clean on all 10 changed files.
RESULT: pass (stub-verified; real Elementor editor verification still needs a live login — handed to
user). No CONTRACTS.md change (Elementor stores control values in its own post data, not our schema).
NEXT: T-M3b (marquee data gaps) or T-R1 (robustness).

## 2026-07-26 — Phase 3 robustness & cleanup (T-R1, Pro T-C4/T-C6/T-C7) — @be/@sec — free 0.12.0 / Pro 0.12.0
CHANGED (free): includes/Reviews/ReviewQuery.php (bulk criteria/media fetch — `criteria_scores_bulk()` /
`media_bulk()`, `to_view()` accepts optional pre-fetched maps), includes/Installer.php (new
`question_votes` table + `table_names()` entry, `NDVR_DB_VERSION` 1→2), includes/Deactivator.php (drops
dead `wp_clear_scheduled_hook('ndv_reviews_daily')` — never scheduled — unschedules the real
`Requests\Scheduler::SEND_HOOK` Action Scheduler job instead), uninstall.php (+unsubscribe option,
+`ndvr_rl_*` rate-limit transients, +pending AS jobs, +review comments/meta via `_ndvr_overall_rating`
marker — confirmed decision to include review data in the opt-in cleanup), templates/review-list.php
(windowed pager), readme.txt, version → 0.12.0.
CHANGED (pro): includes/QandA/QuestionRepository.php (`vote_question()` now `INSERT IGNORE` against the
new `question_votes` unique key, mirrors free `Reviews\Votes`), includes/QandA/QandA.php (`ajax_vote()`
handles the new `WP_Error`), includes/Admin/SettingsPage.php (9 secrets masked value=""+placeholder,
blank-preserves-existing on save; `google_profile_url`/`trustpilot_url` URL fields added; automation
label reworded; badge description corrected), includes/Social/AutoPoster.php (`run()` only marks
`_ndvr_posted` when a configured channel actually succeeded or none is configured — was unconditional),
includes/Admin/ManualReviews.php (`ajax_search()` now `check_ajax_referer()`-gated; manual insert now
fires `ndv-reviews/review_created`), includes/AI/AiService.php (+`cached_summary()`/
`needs_summary_regen()` — cache-only reads, no live API call), includes/AI/Ai.php (`render_summary()`/
`shortcode_summary()` read only the cache and enqueue `ndvr_ai_summary_regen` on Action Scheduler when
stale/missing — no more blocking API call on a visitor's pageview), ndv-reviews-pro.php (+`NDVR_PRO_BASENAME`,
+`load_plugin_textdomain('ndv-reviews-pro')` on init, +Domain Path header), NEW languages/.gitkeep,
includes/Automation/Engine.php (`steps()` no longer parses the dead `automation_steps` JSON setting —
kept the single email/`automation_delay` step; docblocks reworded to stop claiming multi-step/multi-
channel drip), includes/Channel/Message.php (dropped the dead `sms_template`/`wa_template` setting
lookups — always the hardcoded default copy now), includes/Feeds/Badges.php (removed the duplicate
`ndvr-google-badge` registration — `External\ExternalReviews`'s real synced version already won;
`ndvr-trustpilot-badge` renamed to `ndvr-store-rating-badge` / `store_rating()`, since it only ever
showed the on-site WooCommerce aggregate, never live Trustpilot data), includes/External/
ExternalReviews.php (`render_google_badge()` now defaults its `link` attribute to the `google_profile_url`
setting so the badge is clickable without an explicit shortcode attribute), .agents/CONTRACTS.md
(shortcode rename + dedup note), version → 0.12.0.
OBSERVED: `php -l` clean on every changed PHP file in both plugins (checked individually as each was
written). Three product decisions needed user input before implementation (dead automation-settings UI:
strip vs. build — stripped; trust badges: add missing URL fields + rename the misleadingly-branded one —
done; uninstall scope: also delete review comments — confirmed, done). Static verification only this
session — no live WP runtime (DB upgrade path, AJAX round-trips, uninstall dry-run) was exercised; that is
this task's main open risk.
RESULT: pass (static). NEXT: live-site verification per the plan's Verification section (DB upgrade,
N+1 query count, uninstall dry-run, Q&A vote dedup, secrets masking, AutoPoster retry, AI summary async
regen) — then T-M3b, T-A1, or T-L1 (licensing) from the backlog.

## 2026-07-27 — Phase 4: accessibility (T-A1) + UI polish pass (T-UI1) — @fe/@be — free 0.13.0 / Pro 0.13.0
CHANGED (free): includes/Display/Renderer.php (`#ndvr-review-list` gains `aria-live="polite"`/
`aria-busy`; star/topic filter pill buttons gain `aria-pressed`; new `i18n` block in the `ndvrDisplay`
localize array for the lightbox's 4 strings), assets/js/display.js (aria-pressed toggling on pill click,
aria-busy toggling around the AJAX fetch, new photo-lightbox feature — delegated click on
`.ndvr-review-photo`, `role="dialog" aria-modal`, Escape/overlay/close-button dismiss, Left/Right arrow
prev/next scoped to the clicked review's own photo set via the closest `.ndvr-review-media`, focus moves
to the close button on open and back to the trigger link on close, Tab-key focus trap), assets/css/
display.css (new `.ndvr-lightbox*` rules; removed the old per-selector token block — now reads from the
new shared tokens.css), NEW assets/css/tokens.css (`:root` — canonical color/radius/shadow/font tokens),
includes/Support/Assets.php (new `register_tokens()` registers the `ndvr-tokens` handle on
`wp_enqueue_scripts` + the two Elementor style-registration hooks), includes/Display/Widgets.php,
includes/Collection/Landing.php (also defensively self-registers `ndvr-tokens` — this standalone page
never fires `wp_head`/`wp_enqueue_scripts`), includes/Forms/{ReviewForm,TestimonialForm}.php, includes/
Integrations/Widgets/{TopRatedWidget,RecentReviewsWidget}.php (all 7: add `ndvr-tokens` as a style dep),
assets/css/{collect,marquee,reviews}.css (removed their own hand-copied token blocks — reviews.css had
two), assets/css/admin.css (+`--ndvr-shadow` token replacing 3 hand-typed alpha-drifted copies across
admin.css ×2 + design-admin.css ×1; new `.ndvr-stat-row`/`.ndvr-stat`/`.ndvr-analytics-bar-*`/
`.ndvr-pill-row`/`.ndvr-pill` classes for the Pro Analytics reskin; `.ndvr-card .widefat`/`.form-table`
border/shadow reset so tables nested in a card don't double up), assets/css/design-admin.css (shadow now
`var(--ndvr-shadow, ...)`), readme.txt + version → 0.13.0.
CHANGED (pro): includes/Elementor/LoopModule.php (`ndvrDisplay` localize gains the same `i18n` block;
`ndvr-display`/`ndvr-pro-elementor` registration now depends on `ndvr-tokens`), includes/Widgets/
Catalog.php, includes/Social/AutoPoster.php, includes/QandA/QandA.php (all defensively self-register
`ndvr-tokens` before enqueuing, since they can fire outside the normal `wp_enqueue_scripts` timing),
includes/Elementor/GridRenderer.php (elementor.css enqueue gains the dependency), assets/css/qanda.css
(removed its own token block + `.ndvr-qa-message.is-error` now uses `var(--ndvr-rose)`), assets/css/
widgets.css (removed its own token block — this incidentally fixes a real pre-existing bug: the block was
scoped to `.ndvr-carousel,.ndvr-gallery,.ndvr-wall,.ndvr-badge`, but `.ndvr-sidebar-list`/`.ndvr-popup`/
`.ndvr-trust-badge` further down the file aren't nested inside any of those, so their `var(--ndvr-slate)`
etc. never resolved before — the new `:root`-scoped tokens.css reaches them correctly now), assets/css/
elementor.css (2 hardcoded colors — `#0f7d5b`, `#b4462b` — now `var(--ndvr-verdant, ...)`/
`var(--ndvr-rose, ...)`), includes/Analytics/Dashboard.php (reskinned: KPI stat-row at top — total
reviews/blended average/keyword count, computed from data already fetched, no new queries — monthly table
and keyword pills now `.ndvr-card`-wrapped, `#6c8cff` bar → `var(--ndvr-gold)`, `#f0f2f7` pills →
`.ndvr-pill`), includes/Admin/SettingsPage.php (biggest single change — `render()` restructured from one
flat sequence of 14 `<h2>` sections into 5 `.ndvr-tabs` (General / AI & Replies / Automation & Channels /
Moderation & Reputation / Social & Developer) × `.ndvr-card` groups, reusing the exact tab-switching
pattern already in `External\ExternalReviews`; every one of the ~70 existing field names/sanitizer keys
carried over unchanged — verified with a small script that cross-checked every `$keys` sanitizer entry
against a rendered `name="..."` attribute, 0 missing/duplicated other than one pre-existing gap
(`external_target_post` has a sanitizer entry but no field anywhere, predates this change)), version →
0.13.0.
OBSERVED: `php -l`/`node --check` clean on every changed file, both repos. Live-verified in a real browser
(Local site, `?localwp_auto_login=1`): Pro Settings screen — all 5 tabs render, tab-switching JS works, no
double-bordered tables, secret-field masking placeholder still shows correctly, 0 console errors. Pro
Analytics — KPI cards + gold accent bar + pill keywords render correctly with real data (3 reviews, 4.33
avg). Regression-checked External Reviews and Design screens (both untouched but share admin.css) — no
visual change. Product page marquee widget (uses the now-shared tokens.css via marquee.css) — unchanged
visually, 0 console errors. Lightbox itself not yet exercised in a live browser click-through (this test
site's product template doesn't render the native reviews tab with photos) — static/code-level verified
only; flagged as the main open risk for this task, along with the DB/AJAX runtime checks still owed from
Phase 3.
RESULT: pass (static + partial live verification). NEXT: exercise the lightbox live on a product with
photo reviews (open/close/arrow-keys/focus-return); the still-owed Phase 3 live-runtime checks; then
T-M3b (marquee data gaps), T-UI2 (remaining UI polish: free CriteriaPage/RequestsPage/ToolsPage, Pro QandA
Moderation hex colors, the orphaned `external_target_post` setting), or T-L1 (licensing, deferred by
design) from the backlog.

## 2026-07-27 — Phase 5: marquee data gaps (T-M3b) + remaining UI polish (T-UI2) — @fe/@be — free 0.14.0 / Pro 0.14.0
CHANGED (free): includes/Reviews/ReviewQuery.php (`paginate()` gains `category` — new private
`product_ids_for_category()` resolves a `product_cat` term id/slug to product ids via `get_posts()` +
`tax_query`, applied as `post__in` on the `WP_Comment_Query` — and `min_rating`, a server-side `>=`
`meta_query` clause on `_ndvr_overall_rating` mirroring the existing exact-match `star` block), includes/
Display/Widgets.php (`marquee_items()` now passes `category`/`min_rating` into the query instead of
fetching `limit` rows then post-filtering in PHP — the actual cause of the reported starvation, since the
old `array_filter` ran AFTER the DB had already cut the result set to `limit`; `marquee()` gains a `rows`
arg — `rows=2` splits the resolved items across two independent single-row renders via a new
`render_marquee_row()` helper, reusing the existing template unchanged, wrapped in a new
`.ndvr-marquee-rows` div; second row's direction defaults to reversed for a crisscross look), assets/js/
marquee.js (new speed-normalization pass — measures each `.ndvr-marquee-group`'s real rendered width/
height and scales `--ndvr-duration` against a 1200px reference so instances with different review counts
scroll at consistent px/s instead of a flat number of seconds regardless of content width; re-runs
debounced on window resize), assets/css/marquee.css (`.ndvr-marquee-rows` wrapper style),
includes/Integrations/Shortcodes.php (`[ndvr-marquee]` category now accepts a slug OR numeric id, adds
`rows`), includes/Integrations/Blocks.php + assets/js/blocks.js (marquee block gains `category`/`rows`
attributes + editor controls), includes/Integrations/Elementor/Widgets/MarqueeWidget.php (adds Source/
Category/Rows controls), includes/Admin/CriteriaPage.php, includes/Admin/RequestsPage.php,
includes/Admin/ToolsPage.php (all three migrated from raw `.widefat`/`form-table` sections into
`.ndvr-card`-wrapped groups, matching the pattern already used by Design/Settings/ManualReviews/
ExternalReviews — no field names/nonces changed), assets/css/admin.css (new `.ndvr-qr-box` class
replacing ToolsPage's hardcoded `#fff`/`#e6e9ef` QR-code box; new `.ndvr-qa-manual-box`/
`.ndvr-qa-mod-answer`/`.ndvr-qa-mod-answer-label`/`.ndvr-qa-mod-author` classes for Pro's QandA
Moderation), readme.txt + version → 0.14.0.
CHANGED (pro): includes/QandA/Moderation.php (wrapped the manual-add `<details>` box and the questions
table in `.ndvr-card`s; replaced 6 hardcoded hex values — `#f6f7f9`/`#e2e5ea` disclosure background/
border, `#888` muted author, `#f0faf5`/`#0f7d5b` answer-highlight background/border,
`#0f7d5b` label color — with the new admin.css classes), includes/External/ExternalReviews.php (new
"Sync destination (product ID)" field on the Google tab, next to the existing sync-interval control;
saved via a new `save_target_post()` helper using the same get_option/update_option read-modify-write
pattern as `save_sync_interval()` — this finally gives the long-orphaned `external_target_post` setting a
real UI), includes/Admin/SettingsPage.php (removed `external_target_post` from the `handle_save()`
sanitizer whitelist — it never rendered a field there, so every Pro Settings save was silently zeroing the
option via the unconditional `absint('')` default; a latent bug this task's fix would have turned from
invisible-because-always-zero into actively harmful now that External Reviews sets a real value), version
→ 0.14.0.
OBSERVED: `php -l`/`node --check` clean on every changed file, both repos. Live-verified in a real browser
(Local site, `?localwp_auto_login=1`): product-page marquee still renders and animates correctly post
speed-normalization, 0 console errors, no visual regression from the earlier phase's screenshot. Rating
Criteria, Review Reminders, and Import/Export screens (free) all now show proper cards — screenshotted and
compared against the pre-change raw-table appearance. Q&A Moderation (Pro) card-wrapped correctly. External
Reviews' new "Sync destination" field renders with the correct placeholder and sits naturally next to the
existing sync-interval control. Did not runtime-test the category filter against real product-category
data (this dev site's only reviewed products aren't organized into categories) or the double-row variant
in a live shortcode/widget (no test page currently embeds `rows="2"`) — both are code-reviewed and
`php -l`-clean but not exercised end-to-end; flagged as this task's main open risk alongside the
already-noted Phase 3/4 live-runtime checks still owed.
RESULT: pass (static + partial live verification). NEXT: exercise the category filter against seeded
product-category data, the double-row marquee variant, and the still-owed Phase 3/4 live-runtime checks
(lightbox click-through, DB/AJAX/uninstall dry-run) — then T-L1 (licensing, deferred by design) is the
only item left in the backlog.
