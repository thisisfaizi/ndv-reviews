# TASKS.md — the board

Row format:
`| id | title | owner | status | blockers | AC |`
Status: `todo` → `in_progress` → `in_review` → `done`. Update at every transition (§8.1 of AGENTS.md).
Cross-plugin tasks are duplicated on the Pro board with the same id.

## Done (shipped)
| id | title | plugin | shipped |
|---|---|---|---|
| P1–P7 | Original phase roadmap (core reviews → automation → widgets → AI/replies → external → social → pooling/reputation/moderation/CTA/roles) | both | free 0.9.x / Pro 1.7.x |
| S-01 | Review-card element toggles (CardDisplay) | pro | 1.7.6 |
| S-02 | WordPress.org submission prep (readme, extract() removal, license headers) | free | 0.9.7 |
| S-03 | Q&A reCAPTCHA-block fix + manual admin add + `[ndvr-qa]` shortcode | both | free 0.9.8 / pro 1.7.7 |
| S-04 | Google reviews by link → aggregate badge (`[ndvr-google-badge]`) | pro | 1.8.1 |
| S-05 | Elementor Review Grid folded into Review Section widget + 11 part widgets + CSS/style-depends fixes | pro | 1.9.0 |

## In flight
| id | title | owner | status | blockers | AC |
|---|---|---|---|---|---|
| T-00 | USER runtime-verify Elementor editor (parts styled; grid + loop-item modes; each card = different review) | user | in_review | — | parts render styled in editor; grid columns lay out; loop template renders per-review data |

## Phase 1 — DONE (2026-07, free 0.9.9 / Pro 1.9.1)
| id | title | evidence |
|---|---|---|
| T-C1 | Cache (comment meta per review+locale) + per-IP rate-limit + review-type restriction on `ndvr_translate` | php -l clean; `Translate.php` |
| T-B1 | Require ≥1 star rating (client `reviews.js` + server `ReviewForm`); `RatingCache` deletes stale `rating` on recompute-to-zero | php -l + node --check clean |
| T-C2 | Pro settings screens now **merge** into `ndv_reviews_pro_settings` | `SettingsPage.php` array_merge |
| T-B2 | Rating checked before upload; orphaned attachments deleted on create() failure (Review + Testimonial forms); rate limiter counts every attempt | `AntiSpam.php`, both forms |
| T-C3 | New `ndv-reviews/should_send_reminder` filter; Pro `Automation\Engine::suppress_free_reminder()` returns false when automation/ESP/SMS/WA active | both plugins |
| T-C5 | Pro version header 1.7.4 → 1.9.1 (matches constant) | `ndv-reviews-pro.php` |

## Phase 2 — DONE (2026-07, free 0.10.0)
| id | title | evidence |
|---|---|---|
| T-M1 | Marquee seamless loop — animation moved to each `.ndvr-marquee-group` (`translate -100% - gap`); repeat scales with item count to fill viewport | browser harness: motion + no blank gap; 0 console errors |
| T-M2 | Direction left/right/up/down normalized in `Widgets::marquee()`; exposed on shortcode, Gutenberg block, Elementor (legacy horizontal/vertical/reverse still work) | php -l clean |
| T-M3 | Initials avatar (fixes empty `get_avatar`), responsive `clamp()` card width, hover lift; Elementor gains gap/pause/with_media | screenshot: colored initials, styled cards |

## Page-speed pass — DONE (2026-07, free 0.10.1 / Pro 0.10.0)
| id | title | evidence |
|---|---|---|
| PS-1 | Pro `RatingStyles`: glyph CSS moved off the global `wp_enqueue_scripts` onto the lazy `stars_html` path — loads only where stars render | browser: home page loads 0 ndvr assets |
| PS-2 | Minification: `bin/minify-assets.mjs` (esbuild) + `npm run build:assets`; ships `assets/**/*.min.*` (CSS −15-27%, JS −37-52%) | build output; product page serves `*.min.*` |
| PS-3 | `Support\Assets` loader-src filter swaps any `ndvr-*` handle to `.min` in production (off under SCRIPT_DEBUG); one filter covers free + Pro | browser: `display.min.css`, Pro `qanda.min.css` served, 0 console errors |
| PS-4 | Codified "assets load conditionally" invariant in AGENTS.md/CLAUDE.md (both) | — |

## Phase 2.5 — DONE (2026-07, free 0.11.0 / Pro 0.11.0)
| id | title | evidence |
|---|---|---|
| T-ST | Elementor Style tabs for all review widgets. Free: new `WidgetStyleTrait` (color/typography helpers) used by Stars, SummaryWidget, ReviewsWidget, MarqueeWidget — each gains a Style tab (colors, typography, card bg/border/radius/shadow/padding). Pro: `PartBase` gains matching `add_color_control`/`add_typography_control`; `PhotosPart` + `HelpfulPart` get their first Style tab (Helpful includes normal/hover state tabs); `RecommendPart`/`CriteriaPart` normalized to match sibling depth. No render-method changes — pure Elementor `selectors` controls. | Stub-Elementor harness executed `register_controls()` on all 4 free widgets + all 11 Pro parts — 0 runtime errors, confirms untouched parts still work. `php -l` clean on all 10 changed files. |

## Phase 3 — DONE (2026-07-26, free 0.12.0 / Pro 0.12.0)
| id | title | evidence |
|---|---|---|
| T-R1 | Robustness: `ReviewQuery` bulk-fetches criteria+media per page (2 queries, not 2×N); windowed pagination in `review-list.php`; `Deactivator` unschedules the real Action Scheduler reminder job (was clearing a hook that was never scheduled); `uninstall.php` now also clears the unsubscribe option, per-IP rate-limit transients, pending AS jobs, and review comments+meta (opt-in, decision confirmed); new `question_votes` table (`NDVR_DB_VERSION` 1→2) for Pro's Q&A vote dedup | `php -l` clean on all changed files; see PRODUCTION-PLAN §B3/B4/B6 |
| T-C6 (Pro) | Q&A vote dedup via new `question_votes` table (mirrors free `Reviews\Votes`); 9 Pro Settings secrets masked (value="" + placeholder, blank-preserves-existing on save); `AutoPoster` only marks `_ndvr_posted` when a channel actually succeeded or none is configured (was unconditional); `ManualReviews::ajax_search()` now nonce-checked and the manual insert path fires `ndv-reviews/review_created` (AI enrichment/webhooks/moderation alerts now cover admin-added reviews) | `php -l` clean; see PRODUCTION-PLAN §C6 |
| T-C6e (Pro) | AI product summary (`Ai::render_summary()`/`shortcode_summary()`) now reads only the cache — a stale/missing summary is regenerated on Action Scheduler (`ndvr_ai_summary_regen`), never inline on a visitor's request | `php -l` clean; see PRODUCTION-PLAN §C6 |
| T-C7 (Pro) | `load_plugin_textdomain('ndv-reviews-pro')` added on `init` + `/languages` dir created (Pro is not WP.org-hosted, so unlike free it gets no automatic translation loading) | `ndv-reviews-pro.php` |
| T-C4 (Pro) | Dead automation settings (`automation_steps` JSON branch, `sms_template`/`wa_template`) stripped — kept the single working email-delay path; `[ndvr-google-badge]` de-dup resolved (removed from `Feeds\Badges`, kept in `External\ExternalReviews` which now also defaults its link to the `google_profile_url` setting); the on-site aggregate badge renamed `[ndvr-store-rating-badge]` (was `[ndvr-trustpilot-badge]` — never pulled real Trustpilot data) | `php -l` clean; see PRODUCTION-PLAN §C4 |

## Phase 4 — DONE (2026-07-27, free 0.13.0 / Pro 0.13.0)
| id | title | evidence |
|---|---|---|
| T-A1 | Accessibility: review photos now open a keyboard-operable lightbox (`Renderer.php`/`display.js`/`display.css`) — Escape/overlay/close dismiss, arrow-key prev/next scoped to the same review, focus trap + return-to-trigger on close — instead of opening the raw image in a new tab. AJAX review-list container gets `aria-live="polite"`/`aria-busy` so screen readers announce filter/sort/page updates. Star + topic filter pills get `aria-pressed`, synced in JS on click (pagination already had `aria-current` from Phase 3) | `node --check` + `php -l` clean; live-verified in browser, 0 console errors |
| T-UI1 | UI polish pass (both plugins, admin.css shared skin): new shared `assets/css/tokens.css` (free) — a canonical `:root` token source that display/collect/marquee/reviews.css (free) and qanda/widgets.css (Pro) now depend on instead of each hand-copying slightly-drifted token values (fixed 5 different `--ndvr-line` values, 3 different `--ndvr-r` values found across files, plus a real pre-existing bug where `.ndvr-sidebar-list`/`.ndvr-popup`/`.ndvr-trust-badge` in Pro's widgets.css referenced tokens never in scope for their selector). `admin.css` gained a `--ndvr-shadow` token replacing 3 hand-typed alpha-drifted shadow copies. Pro's `Analytics\Dashboard` reskinned into KPI stat cards + `.ndvr-card`-wrapped table/pills (was a bare table with an off-brand `#6c8cff` bar and `#f0f2f7` pills). Pro's `Admin\SettingsPage` (the biggest gap in the UI audit — ~70 settings in one flat `<h2>`/`form-table` wall) restructured into 5 tabs × `.ndvr-card` groups, matching the tab pattern already used by `External\ExternalReviews`; every existing field name/sanitizer key preserved exactly (scripted diff confirmed 0 dropped/duplicated fields) | `php -l`/`node --check` clean; live-verified in browser across all 5 tabs + Analytics + regression-checked Design/External Reviews screens, 0 console errors |

## Phase 5 — DONE (2026-07-27, free 0.14.0 / Pro 0.14.0)
| id | title | evidence |
|---|---|---|
| T-M3b | Marquee data gaps: **category filter** was accepted end-to-end but silently dropped — `ReviewQuery::paginate()` gains `category` (resolves a `product_cat` term id/slug to product ids via `post__in`) and `min_rating` (server-side `>=` on `_ndvr_overall_rating`, replacing the old in-PHP `array_filter` that ran AFTER `per_page` already cut the result set — the actual cause of the starvation bug, since a few recent-but-low-rated reviews could empty out a small limit even when qualifying reviews existed further back). `Widgets::marquee_items()` wires both through instead of post-filtering. **Speed** is now normalized in `marquee.js` — measures each `.ndvr-marquee-group`'s real rendered width/height and scales `--ndvr-duration` against a reference size, so instances with different review counts (and therefore different content width) scroll at a consistent px/s instead of the old flat-seconds-regardless-of-content-width. **Double-row** (`rows=2`) added as a low-risk wrapper — `Widgets::marquee()` calls the existing single-row render path twice with a split item set and opposite directions, wrapped in `.ndvr-marquee-rows`; the `marquee.php` template itself is untouched. All four fixes exposed via `[ndvr-marquee category="" rows="2"]`, the Gutenberg block, and the Elementor widget | `php -l`/`node --check` clean; live-verified on the product page marquee, 0 console errors, no visual regression |
| T-UI2 | Remaining UI polish: free `CriteriaPage`/`RequestsPage`/`ToolsPage` migrated to `.ndvr-card` (previously raw `.widefat`/`form-table`); `ToolsPage`'s QR-code box and Pro `QandA\Moderation`'s manual-add box + inline answer highlight had their hardcoded hex colors replaced with new `.ndvr-qr-box`/`.ndvr-qa-manual-box`/`.ndvr-qa-mod-answer` classes in admin.css referencing the shared tokens; the orphaned `external_target_post` setting now has a real field (a "Sync destination" product-id input on Pro's External Reviews → Google tab, saved via the same read-modify-write helper as the existing sync-interval control) — also fixed a latent bug this surfaced: Pro Settings' sanitizer whitelist still listed `external_target_post` despite never rendering a field for it, so saving Pro Settings would have silently zeroed out the new setting; removed it from that whitelist since External Reviews now owns the field | `php -l` clean; live-verified all 3 free screens + Pro QandA Moderation + the new External Reviews field, 0 console errors |

## Phase 6 — DONE (2026-07-27, free 0.15.0 / Pro 0.15.0) — @qa live-verification pass
Per AGENTS.md §10, the board showed every backlog item shipped except licensing (deferred by design), but
the last 3 phases were only "static + partial live verification" with specific items flagged as never
actually clicked through. Routed to `@qa` to close that debt with the real Local site — exactly the kind
of pass that's supposed to catch what static review can't.

| id | title | evidence |
|---|---|---|
| T-QA1 | Live verification found and fixed **two real, previously-undiscovered bugs**: (1) Pro `ManualReviews.php:195` called `RatingCache::recalc_product()` as a static method — it's an instance method — causing a **fatal error on every "+ Add Review" submission with `approved` checked**; fixed to pull the instance from the DI container (matches the pattern already used in `Developer/Cli.php`). (2) free `Widgets::reviews()` (the `[ndvr-reviews]` shortcode + matching Gutenberg block) never wrapped its output in `#ndvr-reviews`/`#ndvr-review-list` — `display.js`'s init guard requires both, so **the helpful vote button, pagination, and the new Phase 4 lightbox all silently no-op** whenever reviews are shown via shortcode/block outside the native WooCommerce tab; fixed by wrapping the output, and added the `i18n` lightbox strings to this method's own separate `wp_localize_script` call (a second call site missed in Phase 4 alongside `Renderer.php`/Pro's `LoopModule.php`). Also verified live: deactivate → reactivate (both plugins, correct dependency order) — no fatal errors, graceful degradation, clean restoration | `php -l` clean on both fixes; live-verified in browser |
| — | **Explicitly not resolved / deliberately skipped**, recorded rather than silently dropped: the photo lightbox itself couldn't be confirmed opening on the one test product available (its page renders through an Elementor template where `ndvr-display.js`/`ndvr-tokens.css` never appear as network requests at all — most consistent with Elementor's own asset-optimization pipeline interacting with this specific rendering path; ruled out one competing-plugin hypothesis by testing with it deactivated, no change). Category-filtered and double-row marquee shortcodes produced no visible output on the same Elementor-templated product page. A full opt-in **uninstall test was deliberately not run** — this is a shared staging site with other unrelated projects' data, and `uninstall.php` now deletes all review comments too (Phase 3); too destructive to run without a disposable environment | see LOG.md for full detail and recommended next verification environment |

## Phase 6b — DONE (2026-07-27, free 0.15.1) — T-QA2 close-out
| id | title | evidence |
|---|---|---|
| T-QA2 | Closed 3 of T-QA1's 4 owed items live, using a disposable admin-created test page (`[ndvr-reviews product_id]` + both marquee variants) instead of the Elementor-templated product used last pass. **Lightbox:** confirmed `ndvr-display.js`/`marquee.min.js` DO enqueue correctly here — the prior pass's "environmental/Elementor" hypothesis for the missing assets was correct. But clicking the photo surfaced a real, previously-undocumented **second bug**: Elementor's site-wide "Image Lightbox" kit setting (`global_image_lightbox`, on by default) auto-intercepts *any* `<a>` linking to an image file, including `templates/review-item.php`'s photo anchor — opening its own dialog on top of ours simultaneously (confirmed via DOM inspection: both `.ndvr-lightbox-dialog` and Elementor's `.dialog-lightbox-close-button` present at once). Fixed using Elementor's own documented opt-out (`assets/js/frontend.js`'s `isLightboxLink()`): added `data-elementor-open-lightbox="no"` to the photo `<a>` (verified `"none"` does *not* work — Elementor's check is a strict `=== 'no'`). Re-tested: Elementor's dialog no longer appears, ours opens alone, close button dismisses correctly. **Marquee:** both `source=category` and `rows=2` confirmed rendering (3 `.ndvr-marquee` track elements: 1 + 2). **Pro RatingCache fix:** re-submitted "+ Add Review" with approve-immediately live (not just code-inspected this time) — comment created/approved, `_wc_average_rating`/`_wc_review_count` recalculated correctly, no fatal. **Uninstall dry-run:** still deliberately not run — shared staging site, destructive; recommend a disposable install | `php -l` clean; all 3 re-verified live in browser incl. DB-level confirmation of the RatingCache recalc |

## Backlog — from PRODUCTION-PLAN.md (next)
| id | title | owner | status | AC |
|---|---|---|---|---|
| T-L1 | Licensing system (key storage + activation UI + remote check + updater + tier→feature map) | pro/@be | todo | deferred; gate stays open until this ships |
| T-QA2b | Run the full opt-in uninstall flow on a disposable install (not this shared staging site) | @qa | todo | uninstall dry-run confirmed: tables/options/transients/AS jobs/review comments all removed on opt-in, left intact otherwise |
