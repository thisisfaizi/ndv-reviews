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
